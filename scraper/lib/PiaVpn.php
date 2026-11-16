<?php
/**
 * Starts and stops OpenVPN with Private Internet Access (PIA) config files,
 * moving through the servers one after another.
 *
 * OpenVPN is started with a local "management" port, which is how we ask it to
 * shut down cleanly (so Windows gets its normal internet routes back) instead
 * of killing it.
 *
 * Needs OpenVPN 2.5 or newer installed, and PHP running with administrator
 * rights (OpenVPN can't create its network adapter otherwise).
 */
class PiaVpn
{
    private string $openvpn;
    private string $workDir;
    private string $user;
    private string $pass;
    private int $mgmtPort;
    private $log;                 // callable(string): void

    /** @var string[] .ovpn files to cycle through */
    private array $servers;
    private int $next = 0;

    private $proc = null;         // running OpenVPN process
    private ?string $authFile = null;
    private ?string $logFile = null;
    private ?string $current = null;
    private ?bool $isModern = null;

    public function __construct(array $o)
    {
        $this->openvpn  = $o['openvpn'] ?? self::defaultOpenVpnPath();
        $this->workDir  = rtrim($o['workDir'] ?? sys_get_temp_dir(), '/\\');
        $this->user     = (string)($o['user'] ?? '');
        $this->pass     = (string)($o['pass'] ?? '');
        $this->mgmtPort = (int)($o['mgmtPort'] ?? 7505);
        $this->log      = $o['log'] ?? function (string $s) { echo $s, "\n"; };

        if ($this->user === '' || $this->pass === '') {
            throw new RuntimeException('PIA username and password are required.');
        }
        if (!is_file($this->openvpn)) {
            throw new RuntimeException("OpenVPN not found at: {$this->openvpn}");
        }

        $dir = rtrim($o['configDir'], '/\\');
        $regions = $o['regions'] ?? ['us', 'ca'];
        $servers = [];
        foreach ($regions as $r) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . strtolower($r) . '_*.ovpn') ?: [] as $f) $servers[] = $f;
        }
        if (!$servers) {
            throw new RuntimeException("No .ovpn files for regions " . implode(', ', $regions) . " in $dir");
        }
        sort($servers);
        if (!empty($o['shuffle'])) shuffle($servers);
        $this->servers = $servers;

        if (!is_dir($this->workDir)) mkdir($this->workDir, 0700, true);
        register_shutdown_function([$this, 'disconnect']);
    }

    public static function defaultOpenVpnPath(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            foreach (['C:\\Program Files\\OpenVPN\\bin\\openvpn.exe', 'C:\\Program Files (x86)\\OpenVPN\\bin\\openvpn.exe'] as $p) {
                if (is_file($p)) return $p;
            }
            return 'C:\\Program Files\\OpenVPN\\bin\\openvpn.exe';
        }
        foreach (['/usr/sbin/openvpn', '/usr/local/sbin/openvpn', '/opt/homebrew/sbin/openvpn', '/usr/bin/openvpn'] as $p) {
            if (is_file($p)) return $p;
        }
        return '/usr/sbin/openvpn';
    }

    public function serverCount(): int { return count($this->servers); }

    /** Name of the connected server, e.g. "us_texas", or null. */
    public function currentServer(): ?string { return $this->current; }

    /**
     * Disconnect (if connected) and connect to the next server in the list.
     * Tries up to $maxTries servers. Throws if none work, or if PIA rejects the login.
     */
    public function rotate(int $maxTries = 5): string
    {
        $this->disconnect();
        $lastError = '';
        for ($t = 0; $t < min($maxTries, count($this->servers)); $t++) {
            $file = $this->servers[$this->next];
            $this->next = ($this->next + 1) % count($this->servers);
            $name = pathinfo($file, PATHINFO_FILENAME);
            ($this->log)("VPN: connecting to $name ...");
            try {
                $this->connect($file);
                $this->current = $name;
                return $name;
            } catch (VpnAuthException $e) {
                throw $e;                                    // wrong login: no point trying other servers
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                ($this->log)("VPN: $name failed: $lastError");
                $this->disconnect();
                sleep(2);
            }
        }
        throw new RuntimeException("Could not connect to any VPN server ($lastError)");
    }

    /** Is OpenVPN still running? */
    public function isUp(): bool
    {
        if (!$this->proc) return false;
        return proc_get_status($this->proc)['running'];
    }

    /** Ask OpenVPN to shut down cleanly; force it if it doesn't. Safe to call any time. */
    public function disconnect(): void
    {
        if ($this->proc) {
            self::sendSignal($this->mgmtPort);
            for ($i = 0; $i < 50 && proc_get_status($this->proc)['running']; $i++) usleep(200000);
            if (proc_get_status($this->proc)['running']) proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
            if ($this->current) ($this->log)("VPN: disconnected from {$this->current}");
            $this->current = null;
            usleep(1500000);                                 // let the old routes clear
        }
        if ($this->authFile && is_file($this->authFile)) {
            @unlink($this->authFile);
        }
        $this->authFile = null;
    }

    /** Tell whatever OpenVPN is listening on this management port to shut down. */
    public static function sendSignal(int $port = 7505): bool
    {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
        if (!$s) return false;
        stream_set_timeout($s, 2);
        fgets($s);                                           // greeting line
        fwrite($s, "signal SIGTERM\r\n");
        fgets($s);
        fclose($s);
        return true;
    }

    /** Ask a public service what our IP address is. */
    public static function publicIp(): string
    {
        $ch = curl_init('https://api.ipify.org');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 8]);
        $ip = curl_exec($ch);
        curl_close($ch);
        return (is_string($ip) && filter_var(trim($ip), FILTER_VALIDATE_IP)) ? trim($ip) : 'unknown';
    }

    // ------------------------------------------------------------------ internals

    private function connect(string $config): void
    {
        $this->authFile = $this->workDir . DIRECTORY_SEPARATOR . 'pia-auth-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($this->authFile, $this->user . "\n" . $this->pass . "\n");
        @chmod($this->authFile, 0600);

        $this->logFile = $this->workDir . DIRECTORY_SEPARATOR . 'openvpn.log';
        @unlink($this->logFile);

        // Options after --config override the ones inside the .ovpn file.
        $cmd = [
            $this->openvpn,
            '--config', $config,
            '--auth-user-pass', $this->authFile,
            '--management', '127.0.0.1', (string)$this->mgmtPort,
            '--log', $this->logFile,
            '--verb', '3',
            // PIA's standard servers still use AES-128-CBC, which newer OpenVPN won't use unless told to.
            '--data-ciphers', 'AES-128-GCM:AES-256-GCM:AES-128-CBC',
            '--data-ciphers-fallback', 'AES-128-CBC',
            '--allow-compression', 'asym',
            '--connect-retry-max', '1',
        ];
        if ($this->modernOpenVpn()) $cmd[] = '--disable-dco';  // DCO can't do CBC; use the normal adapter

        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $this->proc = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($this->proc)) {
            $this->proc = null;
            throw new RuntimeException('could not start OpenVPN');
        }

        // Wait for OpenVPN to report that the tunnel is up.
        $deadline = time() + 45;
        while (time() < $deadline) {
            usleep(500000);
            $log = is_file($this->logFile) ? (string)file_get_contents($this->logFile) : '';
            if (str_contains($log, 'Initialization Sequence Completed')) {
                @unlink($this->authFile);                    // not needed once connected
                return;
            }
            if (str_contains($log, 'AUTH_FAILED')) {
                $this->disconnect();
                throw new VpnAuthException('PIA rejected the username/password (AUTH_FAILED)');
            }
            if (!proc_get_status($this->proc)['running']) {
                throw new RuntimeException('OpenVPN exited: ' . self::lastLines($log));
            }
        }
        throw new RuntimeException('timed out waiting for the tunnel to come up');
    }

    /** OpenVPN 2.6+ knows --disable-dco; 2.5 would refuse to start with it. */
    private function modernOpenVpn(): bool
    {
        if ($this->isModern === null) {
            $out = [];
            @exec(escapeshellarg($this->openvpn) . ' --version 2>&1', $out);
            $this->isModern = preg_match('/OpenVPN (\d+)\.(\d+)/', implode("\n", $out), $m)
                && ((int)$m[1] > 2 || ((int)$m[1] === 2 && (int)$m[2] >= 6));
        }
        return $this->isModern;
    }

    private static function lastLines(string $log, int $n = 3): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $log)));
        return $lines ? implode(' | ', array_slice($lines, -$n)) : '(no log output - is PHP running as administrator?)';
    }
}

class VpnAuthException extends RuntimeException {}

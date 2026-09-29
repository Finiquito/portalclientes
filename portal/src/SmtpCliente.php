<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Portal;

/**
 * Cliente SMTP mínimo (sin dependencias) para mandar correos multipart HTML + texto.
 * Existe porque el correo del núcleo sólo garantiza texto plano; con esto el diseño llega siempre.
 *
 * Seguridad: 'ssl' (conexión cifrada desde el inicio, puerto 465), 'tls' (STARTTLS, puerto 587)
 * o 'ninguna' (sólo pruebas). Por defecto se valida el certificado del servidor.
 */
final class SmtpCliente
{
    /** Sólo pruebas: aceptar certificados autofirmados. */
    public static bool $verificarCert = true;

    /** @var resource|null */
    private $fp = null;
    private string $ultimo = '';

    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $seguridad,
        private readonly string $usuario,
        private readonly string $clave,
        private readonly int $espera = 20,
        private readonly string $ehlo = 'localhost'
    ) {}

    /** Comprueba conexión y credenciales sin enviar nada. @throws \RuntimeException */
    public function probar(): void
    {
        try {
            $this->conectar();
            $this->cmd('QUIT', [221, 250]);
        } finally {
            $this->cerrar();
        }
    }

    /** @throws \RuntimeException con un mensaje legible */
    public function enviar(string $desde, string $nombreDesde, string $para, string $asunto, string $html, string $texto, ?string $responderA = null): void
    {
        try {
            $this->conectar();
            $this->cmd('MAIL FROM:<' . $this->limpio($desde) . '>', [250]);
            $this->cmd('RCPT TO:<' . $this->limpio($para) . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            $msg = $this->mensaje($desde, $nombreDesde, $para, $asunto, $html, $texto, $responderA);
            // Dot-stuffing: una línea que empieza con «.» se duplica.
            $msg = preg_replace('/^\./m', '..', $msg) ?? $msg;
            $this->escribir($msg . "\r\n.\r\n");
            $this->leer([250]);
            $this->cmd('QUIT', [221, 250]);
        } finally {
            $this->cerrar();
        }
    }

    // ---- Protocolo ----------------------------------------------------------

    private function conectar(): void
    {
        $destino = ($this->seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->puerto;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => self::$verificarCert, 'verify_peer_name' => self::$verificarCert, 'allow_self_signed' => !self::$verificarCert,
            'peer_name' => $this->host, 'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $err = '';
        $fp = @stream_socket_client($destino, $errno, $err, $this->espera, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new \RuntimeException('No se pudo conectar a ' . $this->host . ':' . $this->puerto . ($err !== '' ? " ({$err})" : '') . '. Revisa servidor, puerto y seguridad.');
        }
        $this->fp = $fp;
        stream_set_timeout($this->fp, $this->espera);
        $this->leer([220]);
        $r = $this->cmd('EHLO ' . $this->ehlo, [250]);
        if ($this->seguridad === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('El servidor no aceptó la conexión segura (STARTTLS).');
            }
            $r = $this->cmd('EHLO ' . $this->ehlo, [250]);
        }
        if ($this->usuario !== '') {
            $this->autenticar($r);
        }
    }

    private function autenticar(string $ehloResp): void
    {
        $soporta = strtoupper($ehloResp);
        try {
            if (str_contains($soporta, 'AUTH') && !str_contains($soporta, 'PLAIN') && str_contains($soporta, 'LOGIN')) {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->usuario), [334]);
                $this->cmd(base64_encode($this->clave), [235]);
            } else {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $this->usuario . "\0" . $this->clave), [235]);
            }
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('El servidor rechazó el usuario o la contraseña de correo (' . trim($this->ultimo) . ').');
        }
    }

    /** @param array<int> $esperados */
    private function cmd(string $linea, array $esperados): string
    {
        $this->escribir($linea . "\r\n");
        return $this->leer($esperados);
    }

    private function escribir(string $datos): void
    {
        $fp = $this->fp;
        if ($fp === null || @fwrite($fp, $datos) === false) {
            throw new \RuntimeException('Se cortó la conexión con el servidor de correo.');
        }
    }

    /** @param array<int> $esperados @return string respuesta completa */
    private function leer(array $esperados): string
    {
        $resp = '';
        $fp = $this->fp;
        while ($fp !== null && ($l = fgets($fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $this->ultimo = $resp;
        if ($resp === '') {
            throw new \RuntimeException('El servidor de correo no respondió a tiempo.');
        }
        if (!in_array((int) substr($resp, 0, 3), $esperados, true)) {
            throw new \RuntimeException('El servidor de correo respondió: ' . trim(mb_substr($resp, 0, 200)));
        }
        return $resp;
    }

    private function cerrar(): void
    {
        if ($this->fp !== null) {
            @fclose($this->fp);
            $this->fp = null;
        }
    }

    // ---- Mensaje MIME ---------------------------------------------------------

    private function limpio(string $s): string
    {
        return str_replace(["\r", "\n", '<', '>'], '', $s);
    }

    private function cabecera(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return preg_match('/^[\x20-\x7e]*$/', $s) === 1 ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private function b64(string $s): string
    {
        return rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
    }

    private function mensaje(string $desde, string $nombreDesde, string $para, string $asunto, string $html, string $texto, ?string $responderA): string
    {
        $borde = 'b_' . bin2hex(random_bytes(12));
        $dominio = substr(strrchr($desde, '@') ?: '@localhost', 1);
        $h = [
            'Date: ' . date('r'),
            'From: ' . ($nombreDesde !== '' ? $this->cabecera($nombreDesde) . ' ' : '') . '<' . $this->limpio($desde) . '>',
            'To: <' . $this->limpio($para) . '>',
            'Subject: ' . $this->cabecera($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->limpio($dominio) . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/alternative; boundary="' . $borde . '"',
        ];
        if ($responderA !== null && $responderA !== '') {
            $h[] = 'Reply-To: <' . $this->limpio($responderA) . '>';
        }
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $html  = str_replace(["\r\n", "\r"], "\n", $html);
        return implode("\r\n", $h) . "\r\n\r\n"
            . '--' . $borde . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $this->b64($texto) . "\r\n"
            . '--' . $borde . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $this->b64($html) . "\r\n"
            . '--' . $borde . '--';
    }
}

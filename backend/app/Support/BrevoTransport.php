<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Envía los correos por la API web de Brevo (HTTPS) con el cliente HTTP de Laravel.
 *
 * Railway bloquea el SMTP, por eso no se usa. Se activa con MAIL_MAILER=brevo y BREVO_API_KEY
 * (ver config/mail.php, config/services.php y AppServiceProvider). Si Brevo falla lanza
 * TransportException; quien envía decide qué mostrar al usuario.
 *
 * @CU CU-01 (flujo alternativo: recuperar contraseña)
 */
class BrevoTransport extends AbstractTransport
{
    public const URL = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly ?string $clave)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (blank($this->clave)) {
            throw new TransportException('Falta BREVO_API_KEY: no se puede enviar el correo.');
        }

        $correo = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $remitente = $correo->getFrom()[0] ?? $envelope->getSender();
        $texto = $correo->getTextBody();
        $html = $correo->getHtmlBody() ?: nl2br(e((string) $texto));

        $datos = [
            'sender' => $this->direccion($remitente),
            'to' => array_map(fn (Address $a) => $this->direccion($a), $envelope->getRecipients()),
            'subject' => (string) $correo->getSubject(),
            'htmlContent' => (string) $html,
        ];

        if (filled($texto)) {
            $datos['textContent'] = (string) $texto;
        }

        $respuesta = Http::withHeaders(['api-key' => $this->clave])
            ->acceptJson()
            ->timeout(15)
            ->post(self::URL, $datos);

        if ($respuesta->failed()) {
            throw new TransportException('Brevo respondió '.$respuesta->status().': '.$respuesta->body());
        }

        if ($id = $respuesta->json('messageId')) {
            $message->setMessageId(trim((string) $id, '<>'));
        }
    }

    /** @return array{email: string, name?: string} */
    private function direccion(Address $a): array
    {
        return array_filter(['email' => $a->getAddress(), 'name' => $a->getName()], fn ($v) => $v !== '');
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }
}

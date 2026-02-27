# PlaticaNubecita

Chat 1 a 1 con Symfony 7.4, autenticación completa, API interna y cifrado de mensajes/adjuntos en base de datos.

## Características

- Registro por enlace enviado a correo.
- Login por sesión y listado de cuentas registradas.
- Cambio entre chats 1 a 1 y polling cada 2.5s.
- Subida de archivos, imágenes, audio y video `.mp4`.
- Recuperación de contraseña por correo.
- Cifrado en reposo para correo, mensajes y adjuntos.
- Estilo visual morado/azul/rosa con tipografía moderna y grande.

## Stack

- PHP 8.2+
- Symfony 7.4 LTS
- Doctrine ORM + Migrations
- Twig + HTML + CSS + JS Vanilla
- MySQL (Hostinger)

## Variables de entorno

Copia `.env.example` a `.env.local` y ajusta:

- `APP_SECRET`
- `DATABASE_URL`
- `MAILER_DSN`
- `MAILER_FROM`
- `APP_ENCRYPTION_KEY` (base64 de 32 bytes)
- `APP_TOKEN_HASH_KEY`
- `APP_MAX_UPLOAD_BYTES` (25MB por defecto)

## Ejecución local

```bash
php /tmp/composer-local install
php bin/console doctrine:migrations:migrate --no-interaction
php -S 127.0.0.1:8000 -t public
```

Abre: `http://127.0.0.1:8000`

## Pruebas

```bash
php bin/phpunit
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync
```

## Endpoints API

- `POST /api/auth/request-registration-link`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`
- `GET /api/users`
- `GET /api/chats`
- `POST /api/chats`
- `GET /api/chats/{chatId}/messages?afterId=`
- `POST /api/chats/{chatId}/messages`
- `GET /api/attachments/{attachmentId}`

## Seguridad aplicada

- Password hashing con Argon2id (`algorithm: auto`).
- Blind index (`email_hash`) para búsquedas sin correo en claro.
- Cifrado simétrico de contenido (libsodium secretbox).
- CSRF en operaciones mutantes.
- Rate limiting para login y auth pública.
- Cookies seguras con `HttpOnly`, `Secure(auto)`, `SameSite=Lax`.

## Cron recomendado

Cada 5 minutos:

```bash
php bin/console app:tokens:cleanup --env=prod
```

## Deploy Hostinger

Consulta [HOSTINGER_DEPLOY.md](HOSTINGER_DEPLOY.md).

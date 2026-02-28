# PlaticaNubecita

Aplicacion de chat 1 a 1 construida con Symfony 7.4, orientada a despliegue simple en Hostinger, con autenticacion completa y cifrado de datos sensibles.

## 1. Que incluye este proyecto

- Registro por enlace (magic link) enviado por correo.
- Login por sesion web.
- Recuperacion de contrasena por correo.
- Lista de cuentas registradas al iniciar sesion.
- Chat 1 a 1 con cambio de conversacion.
- Polling cada 2.5 segundos para mensajes nuevos.
- Adjuntos (archivos, imagenes, audio y video `.mp4`).
- Cifrado en base de datos para:
  - correo de usuario (almacenado cifrado + indice ciego)
  - mensajes
  - adjuntos
  - metadatos sensibles de adjuntos

## 2. Stack tecnico

- PHP + Symfony 7.4 LTS
- Doctrine ORM + Doctrine Migrations
- Twig + HTML + CSS + JavaScript vanilla
- MySQL/MariaDB (Hostinger)
- Symfony Mailer (SMTP Hostinger)

## 3. Requisitos de entorno

## Local

- PHP 8.4 recomendado (8.2 minimo en `composer.json`, pero dependencias actuales piden 8.4)
- Composer 2
- Extensiones:
  - `pdo_mysql`
  - `openssl`
  - `mbstring`
  - `intl`
  - `xml`

## Produccion (Hostinger)

- PHP 8.4 activo para el subdominio.
- MySQL creado y accesible.
- SMTP activo para envio de correos.

Nota importante: el proyecto soporta cifrado con `sodium` si existe, y hace fallback automatico a `OpenSSL AES-256-GCM` si `sodium` no esta disponible.

## 4. Estructura funcional

- Auth API: `src/Controller/ApiAuthController.php`
- Chat API: `src/Controller/ApiChatController.php`
- Cifrado: `src/Service/EncryptionService.php`
- Tokens: `src/Service/TokenService.php`
- Limpieza de tokens: `src/Command/CleanupTokensCommand.php`
- Vistas:
  - `templates/auth/*`
  - `templates/chat/index.html.twig`
- Frontend:
  - `public/css/app.css`
  - `public/js/auth.js`
  - `public/js/chat.js`

## 5. Configuracion de variables de entorno

Usa `.env.example` como base y crea `.env.local`.

Variables clave:

- `APP_ENV=prod`
- `APP_SECRET`
- `APP_BASE_URL`
- `DEFAULT_URI`
- `DATABASE_URL`
- `MAILER_DSN`
- `MAILER_FROM`
- `APP_ENCRYPTION_KEY` (base64, 32 bytes)
- `APP_ENCRYPTION_KEY_VERSION=1`
- `APP_TOKEN_HASH_KEY` (string aleatorio largo)
- `APP_MAX_UPLOAD_BYTES=26214400` (25 MB)

## Ejemplo `DATABASE_URL` Hostinger

```dotenv
DATABASE_URL="mysql://USER:PASSWORD@localhost:3306/DB_NAME?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
```

## Ejemplo `MAILER_DSN` Hostinger

```dotenv
MAILER_DSN="smtp://EMAIL%40dominio.com:PASSWORD@smtp.hostinger.com:465?encryption=ssl&auth_mode=login"
```

## 6. Ejecucion local

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php -S 127.0.0.1:8000 -t public
```

Abrir:

- `http://127.0.0.1:8000`

## 7. Pruebas y validaciones

```bash
php bin/phpunit
php bin/console lint:container
php bin/console lint:twig templates
php bin/console lint:yaml config
php bin/console doctrine:schema:validate --skip-sync
```

## 8. API (resumen)

## Auth

- `POST /api/auth/request-registration-link`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`

## Chat

- `GET /api/users`
- `GET /api/chats`
- `POST /api/chats`
- `GET /api/chats/{chatId}/messages?afterId=`
- `POST /api/chats/{chatId}/messages`
- `GET /api/attachments/{attachmentId}`

## 9. Seguridad implementada

- Hash de contrasena con hasher de Symfony (argon/bcrypt segun entorno).
- Correo no se guarda en claro:
  - `email_ciphertext` (cifrado)
  - `email_hash` (blind index para busqueda)
- Cifrado simetrico de mensajes y adjuntos.
- CSRF en operaciones mutantes.
- Rate limiting para login y flujos publicos.
- Cookies seguras:
  - `HttpOnly`
  - `Secure`
  - `SameSite=Lax`

## 10. Despliegue en Hostinger (paso a paso)

## 1) Preparacion

- Crear subdominio.
- Activar SSH.
- Crear DB MySQL y usuario.
- Crear correo para SMTP.
- Verificar PHP 8.4.

## 2) Subir codigo

En carpeta del subdominio (`public_html`):

```bash
git init -b main
git remote add origin https://github.com/ferruzxca/PlaticaNubecita.git
git fetch --depth=1 origin main
git reset --hard origin/main
```

## 3) Instalar dependencias

En Hostinger:

```bash
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-redis
```

## 4) Configurar `.env.local`

Crear en `public_html/.env.local` con tus valores de prod.

## 5) Activar PHP 8.4 a nivel web

El proyecto incluye en `.htaccess`:

```apache
AddHandler application/x-httpd-alt-php84___lsphp .php .php8 .phtml
```

Esto evita que la web corra en PHP 8.3.

## 6) Base de datos

Primera vez (si no hay tablas):

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:schema:create --no-interaction
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:migrations:version "DoctrineMigrations\\Version20260227214856" --add --no-interaction
```

Siguientes despliegues:

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:migrations:migrate --no-interaction
```

## 7) Cache

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console cache:clear --no-warmup
```

## 8) Cron (limpieza de tokens)

En hPanel > Cron Jobs:

- expresion: `*/5 * * * *`
- comando:

```bash
/opt/alt/php84/usr/bin/php /home/u186891664/domains/chat.ferruzca.pro/public_html/bin/console app:tokens:cleanup --env=prod >> /home/u186891664/domains/chat.ferruzca.pro/public_html/var/log/cron.log 2>&1
```

## 11. Verificacion funcional recomendada

1. Abrir `/` y confirmar pantalla de login.
2. Solicitar link de registro.
3. Registrar usuario nuevo.
4. Iniciar sesion.
5. Ver lista de cuentas.
6. Crear chat y enviar mensaje.
7. Subir adjunto (imagen/audio/mp4/archivo).
8. Descargar adjunto.
9. Probar flujo de "Olvide mi contrasena".

## 12. Troubleshooting rapido

## Error `Composer dependencies require PHP >= 8.4`

- La web o CLI esta en PHP 8.3.
- Solucion:
  - usar `/opt/alt/php84/usr/bin/php` para comandos CLI
  - asegurar `AddHandler ... php84 ...` en `.htaccess`

## Error `Token CSRF invalido`

- Verificar que el formulario cargue token fresco desde la misma sesion.
- No reutilizar cookies/token expirados.

## Error `Access denied for user ...` MySQL

- Revisar `DATABASE_URL` en `.env.local`.
- Verificar usuario/password/DB exactos.
- Si hay caracteres especiales en password, escapar correctamente.

## 13. Operacion y seguridad

- No versionar secretos en git.
- Rotar periodicamente:
  - `APP_SECRET`
  - password DB
  - password SMTP
  - password SSH
- Respaldar DB y `var/log`.

## 14. Documentacion adicional

- Deploy detallado: `HOSTINGER_DEPLOY.md`
- Variables base: `.env.example`

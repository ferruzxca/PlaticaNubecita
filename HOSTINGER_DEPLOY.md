# Despliegue en Hostinger (Subdominio) - v2

## 1) Preparación en hPanel

1. Crear subdominio.
2. Activar SSH.
3. Crear base MySQL y usuario.
4. Crear correo para SMTP.
5. Confirmar PHP 8.4 en el subdominio.
6. Ajustar PHP:
- `upload_max_filesize=25M`
- `post_max_size=30M`
- `max_execution_time=120`
- `memory_limit=256M`

## 2) Subida del proyecto

Dentro de la carpeta del subdominio (`public_html`):

```bash
git clone https://github.com/TU_USUARIO/PlaticaNubecita.git .
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-redis
```

## 3) Configurar `.env.local`

Archivo: `/home/TU_USER/domains/TU_SUBDOMINIO/public_html/.env.local`

Variables mínimas:

```dotenv
APP_ENV=prod
APP_SECRET=...
APP_BASE_URL=https://chat.tudominio.com
DEFAULT_URI=https://chat.tudominio.com

DATABASE_URL="mysql://USER:PASSWORD@localhost:3306/DB?serverVersion=10.11.2-MariaDB&charset=utf8mb4"

MAILER_DSN="smtp://USUARIO%40dominio.com:PASSWORD@smtp.hostinger.com:465?encryption=ssl&auth_mode=login"
MAILER_FROM="Nombre <USUARIO@dominio.com>"

APP_ENCRYPTION_KEY=BASE64_32_BYTES
APP_ENCRYPTION_KEY_VERSION=1
APP_TOKEN_HASH_KEY=SECRETO_LARGO
APP_MAX_UPLOAD_BYTES=26214400
APP_MAX_AVATAR_BYTES=2097152

AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
AI_MODEL=gpt-4.1-mini
AI_TIMEOUT_SECONDS=20
AI_MAX_OUTPUT_TOKENS=500
```

## 4) Migraciones

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:migrations:migrate --no-interaction
```

## 5) Cache prod

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console cache:clear --no-warmup
```

## 6) DocumentRoot

- Recomendado: apuntar subdominio a `public/`.
- Si no se puede, usar `.htaccess` raíz para redirigir a `public`.

## 7) Cron obligatorio (tokens)

Cada 5 minutos:

```bash
/opt/alt/php84/usr/bin/php /home/TU_USER/domains/TU_SUBDOMINIO/public_html/bin/console app:tokens:cleanup --env=prod >> /home/TU_USER/domains/TU_SUBDOMINIO/public_html/var/log/cron.log 2>&1
```

## 8) Smoke test post-deploy

1. Abrir `/`.
2. Registrar/entrar usuario.
3. Validar perfil (nombre/estado/avatar).
4. Validar chat con `Nubecita IA`.
5. Crear grupo y enviar mensajes.
6. Probar adjuntos y preview.
7. Probar reset de contraseña por correo.

## 9) Troubleshooting rápido

- Error de IA:
  - revisar `OPENAI_API_KEY`, cuota y conectividad saliente.
- Error CSRF:
  - limpiar cookies/sesión y reintentar.
- Error MySQL:
  - validar `DATABASE_URL` y credenciales.
- Error de subida avatar:
  - confirmar tipo JPG/PNG/WebP y tamaño <= 2MB.

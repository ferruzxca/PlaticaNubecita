# PlaticaNubecita

Aplicación de chat cifrado con Symfony 7.4 lista para Hostinger.

## v2 Features

- Auth completo: registro por link, login, reset de contraseña.
- Chat 1 a 1 cifrado con polling cada 2.5s.
- Chatbot IA `Nubecita IA` (OpenAI) en chat dedicado 1:1.
- Perfil editable:
  - nombre visible,
  - estado,
  - foto de perfil (archivo o cámara).
- Chats de grupo:
  - crear grupo,
  - renombrar,
  - agregar/quitar miembros,
  - salir de grupo.
- Adjuntos cifrados en base de datos con vista mejorada:
  - preview inline para imagen/audio/video,
  - tarjeta de descarga para archivos generales.

## Seguridad y cifrado

- Contraseñas con hasher de Symfony (Argon/Bcrypt según entorno).
- Correo no se guarda en claro:
  - `email_hash` para búsqueda,
  - `email_ciphertext` cifrado.
- Cifrado en reposo para:
  - mensajes,
  - adjuntos,
  - estado de perfil,
  - nombre de grupo.
- Avatar: almacenamiento simple en disco para máxima compatibilidad (ver sección “Avatar (simple)”).
- CSRF en mutaciones.
- Rate limiting para auth y para respuestas IA.
- Cookies de sesión seguras (`HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS).

## Stack

- Symfony 7.4
- PHP 8.2+ (recomendado 8.4+)
- Doctrine ORM/Migrations
- Twig + JS/CSS vanilla
- MySQL/MariaDB (Hostinger)
- SMTP Hostinger

## Variables de entorno

Base:

- `APP_ENV`
- `APP_SECRET`
- `APP_BASE_URL`
- `DEFAULT_URI`
- `DATABASE_URL`
- `MAILER_DSN`
- `MAILER_FROM`
- `APP_ENCRYPTION_KEY`
- `APP_ENCRYPTION_KEY_VERSION`
- `APP_TOKEN_HASH_KEY`
- `APP_MAX_UPLOAD_BYTES=26214400`
- `APP_MAX_AVATAR_BYTES=5242880`

IA:

- `AI_PROVIDER=openai` (`mock` para pruebas/local)
- `OPENAI_API_KEY=`
- `AI_MODEL=gpt-4.1-mini`
- `AI_TIMEOUT_SECONDS=20`
- `AI_MAX_OUTPUT_TOKENS=500`

Ejemplo `DATABASE_URL` Hostinger:

```dotenv
DATABASE_URL="mysql://USER:PASSWORD@localhost:3306/DB_NAME?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
```

Ejemplo `MAILER_DSN` Hostinger:

```dotenv
MAILER_DSN="smtp://EMAIL%40dominio.com:PASSWORD@smtp.hostinger.com:465?encryption=ssl&auth_mode=login"
```

## Endpoints API

Auth:

- `POST /api/auth/request-registration-link`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`

Perfil:

- `GET /api/profile`
- `POST /api/profile` (multipart)
- `GET /api/profile/avatar/{userId}`

## Avatar (simple)

Para evitar errores en Safari/Firefox y asegurar compatibilidad:

- El avatar se guarda en `public/uploads/avatars/{userId}.{ext}`.
- La API devuelve el avatar desde archivo (endpoint `/api/profile/avatar/{userId}`).
- Si no existe archivo, se usa fallback de iniciales en la UI.

Permisos requeridos en producción:

```bash
mkdir -p public/uploads/avatars
chmod 755 public/uploads/avatars
```

Chat:

- `GET /api/users`
- `GET /api/chats`
- `POST /api/chats` (directo)
- `POST /api/chats/groups`
- `PATCH /api/chats/{chatId}/group`
- `GET /api/chats/{chatId}/members`
- `POST /api/chats/{chatId}/members`
- `DELETE /api/chats/{chatId}/members/{userId}`
- `POST /api/chats/{chatId}/leave`
- `GET /api/chats/{chatId}/messages?afterId=`
- `POST /api/chats/{chatId}/messages`
- `GET /api/attachments/{attachmentId}?disposition=inline|attachment`

## Reglas de grupos

- Creador del grupo = admin inicial.
- Admin puede renombrar, agregar y quitar miembros.
- Cualquier miembro puede salir.
- Si no queda admin, se promueve el miembro más antiguo.
- Límite: 50 miembros por grupo.

## Desarrollo local

```bash
php -v
php bin/console doctrine:migrations:migrate --no-interaction
php -S 127.0.0.1:8000 -t public
```

Abrir: `http://127.0.0.1:8000`

## Pruebas

```bash
php bin/phpunit
php bin/console lint:container
php bin/console lint:twig templates
php bin/console lint:yaml config
```

## Despliegue Hostinger (resumen)

1. Subir repositorio al subdominio (`public_html`).
2. Instalar dependencias con PHP 8.4:

```bash
/opt/alt/php84/usr/bin/php /usr/local/bin/composer2 install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-redis
```

3. Configurar `.env.local` con variables de producción.
4. Ejecutar migraciones:

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:migrations:migrate --no-interaction
```

5. Limpiar caché:

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console cache:clear --no-warmup
```

6. Confirmar cron activo para limpieza de tokens.

## Checklist de aceptación v2

- Registro/login/reset funcionando como antes.
- Edición de perfil (nombre/estado/avatar) operativa.
- `Nubecita IA` visible y respondiendo en chat dedicado.
- Grupos: crear/renombrar/agregar/quitar/salir funcionando.
- Adjuntos cifrados con preview inline.
- Sin mensajes/correos en texto plano en SQL.

## Troubleshooting IA

- `OPENAI_API_KEY` vacía o inválida:
  - el chat responde con error controlado de IA.
- Timeout a OpenAI:
  - subir `AI_TIMEOUT_SECONDS`.
- Límite de cuota/requests:
  - revisar cuota OpenAI y rate limits de la app.

## Rollback de despliegue

1. Regresar al commit estable:

```bash
git fetch origin
git reset --hard <commit_estable>
```

2. Ejecutar migración de rollback solo si necesitas revertir esquema:

```bash
APP_ENV=prod /opt/alt/php84/usr/bin/php bin/console doctrine:migrations:migrate prev --no-interaction
```

3. Limpiar caché y validar login/chat.

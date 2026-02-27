# Despliegue en Hostinger (Subdominio)

## 1) Preparación en hPanel

1. Crea el subdominio.
2. Activa SSH en el plan.
3. Crea la base de datos MySQL.
4. Crea una cuenta de correo para SMTP del dominio.
5. Configura PHP 8.2+ y ajusta:
- `upload_max_filesize=25M`
- `post_max_size=30M`
- `max_execution_time=120`
- `memory_limit=256M`

## 2) Subida del proyecto

En SSH, dentro del directorio del subdominio:

```bash
git clone https://github.com/TU_USUARIO/PlaticaNubecita.git .
composer2 install --no-dev --optimize-autoloader
```

## 3) Configuración de entorno

Crea `.env.local` en servidor con valores de producción:

- `APP_ENV=prod`
- `APP_SECRET`
- `DATABASE_URL`
- `MAILER_DSN`
- `MAILER_FROM`
- `APP_ENCRYPTION_KEY`
- `APP_TOKEN_HASH_KEY`

## 4) Base de datos

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

## 5) DocumentRoot

- Opción recomendada: apunta el subdominio a la carpeta `public/`.
- Si no es posible, usa el `.htaccess` raíz para redirigir a `public`.

## 6) Cron para limpieza de tokens

Crear cron cada 5 minutos:

```bash
php /home/USER/domains/TU_DOMINIO/public_html/SUBDOMINIO/bin/console app:tokens:cleanup --env=prod
```

## 7) Verificación rápida

1. Abrir `/` y solicitar enlace de registro.
2. Crear cuenta y entrar.
3. Ver listado de cuentas.
4. Abrir chat, enviar mensaje y adjunto.
5. Probar “Olvidé contraseña”.

# Despliegue en cPanel sin Node ni npm

Este proyecto no necesita ejecutar Node ni npm en producción. Las vistas Blade
cargan directamente los recursos que ya están versionados en:

- `public/css/style.css`
- `public/js/*.js`

El directorio `public/build` también puede incluirse en Git si en el futuro se
empieza a usar Vite. La compilación deberá realizarse localmente antes del
`push`.

## Requisitos del hosting

- PHP 8.3 o superior.
- Extensiones PHP requeridas por Laravel y por el motor de base de datos.
- MySQL o MariaDB.
- Acceso SSH o la herramienta **Git Version Control** de cPanel.
- Composer para la instalación inicial y cuando cambie `composer.lock`.

Node y npm no son requisitos del servidor.

## Estructura recomendada

El repositorio debe quedar fuera de `public_html`, por ejemplo:

```text
/home/USUARIO/repositorios/sistema-pollos
```

El dominio o subdominio debe tener como raíz pública:

```text
/home/USUARIO/repositorios/sistema-pollos/public
```

Esto es necesario porque únicamente el contenido de `public` debe ser accesible
desde Internet. No se debe exponer `.env`, `vendor`, `storage`, el código de la
aplicación ni los archivos de configuración.

## Instalación inicial

Desde la terminal de cPanel:

```bash
cd /home/USUARIO/repositorios/sistema-pollos
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Después se deben configurar en `.env` la URL, la base de datos y el entorno de
producción:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dominio.example
```

También hay que dar permisos de escritura al usuario de PHP sobre:

```text
storage
bootstrap/cache
```

## Actualizaciones normales

Cuando solo cambie código PHP, Blade, CSS o JavaScript ya versionado:

```bash
cd /home/USUARIO/repositorios/sistema-pollos
git pull --ff-only
php artisan optimize:clear
php artisan optimize
```

No se ejecuta Node ni npm.

Si hay migraciones nuevas:

```bash
php artisan migrate --force
```

Si cambió `composer.lock`:

```bash
composer install --no-dev --optimize-autoloader
```

## Si se empieza a usar Vite

La compilación se realiza en el equipo de desarrollo:

```bash
npm install
npm run build
git add public/build
git commit -m "Compilar recursos para producción"
git push
```

En cPanel únicamente se recibe `public/build` mediante `git pull`.

No se debe subir `node_modules`.

## Comprobación antes del push

Las vistas actuales no usan la directiva `@vite`. Si se introduce esa directiva,
el commit debe incluir también:

```text
public/build/manifest.json
```

Sin ese manifiesto Laravel producirá un error al renderizar la vista en
producción.

## Sobre `vendor`

`vendor` no se guarda en Git. Esta es la práctica correcta para Laravel, pero
significa que Composer debe ejecutarse durante la instalación inicial y cada vez
que cambien las dependencias PHP.

Si el hosting no ofrece Composer, se pueden instalar las dependencias localmente
con la misma versión de PHP y subir `vendor` por SFTP como excepción. No se
recomienda versionarlo en Git.

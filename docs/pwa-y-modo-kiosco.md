# PWA y modo kiosco

Sistema Pollos puede instalarse en Windows como PWA y también abrirse mediante un acceso directo en modo kiosco.

## Requisitos

- En producción, el sitio debe publicarse con HTTPS. `localhost` también funciona durante el desarrollo.
- Microsoft Edge o Google Chrome debe estar instalado en el equipo.
- El certificado HTTPS debe ser válido y de confianza para Windows.

## Instalar la PWA

1. Abra el sistema en Edge o Chrome e inicie sesión.
2. En Edge use **Aplicaciones > Instalar Sistema Pollos**. En Chrome use el icono **Instalar** de la barra de direcciones.
3. Windows creará un icono que abre el sistema como una aplicación independiente, sin pestañas ni barra de direcciones normal.

La PWA mejora la presentación, pero el usuario todavía puede cerrar o restaurar su ventana. Para una pantalla operativa fija use además el acceso directo kiosco.

### Pantalla del cliente en un segundo monitor

Desde la aplicación instalada en Windows, el botón **Pantalla cliente** abre una segunda ventana de Sistema Pollos. Muévala al monitor frontal y active **Pantalla completa**. Ambas ventanas permanecen sincronizadas en tiempo real.

Si el sistema se abre en una pestaña normal de Edge o Chrome, el mismo botón abre una pestaña del navegador. Las PWA instaladas en Windows no ofrecen actualmente una barra de pestañas nativa; por eso se utiliza una segunda ventana de la misma aplicación, que además puede trasladarse de forma independiente al segundo monitor.

## Crear el acceso directo kiosco

El instalador ya usa `https://sada-csa.com/` como dirección predeterminada. Abra PowerShell en la carpeta del proyecto y ejecute:

```powershell
.\scripts\Install-SistemaPollosKiosk.ps1
```

El instalador selecciona Google Chrome cuando está disponible y usa Microsoft Edge como respaldo. Para forzar Microsoft Edge:

```powershell
.\scripts\Install-SistemaPollosKiosk.ps1 -Browser Edge
```

También puede descargar únicamente `Install-SistemaPollosKiosk.ps1` y ejecutarlo desde cualquier carpeta. El instalador es autónomo: se copia junto con su configuración a `%LOCALAPPDATA%\SistemaPollos\KioskLauncher`, por lo que el archivo descargado puede eliminarse después de la instalación.

Para instalar otro entorno se conserva la opción de indicar una URL diferente:

```powershell
.\Install-SistemaPollosKiosk.ps1 -Url "https://otro-dominio.example/" -Browser Chrome
```

El acceso se crea en el escritorio y conserva la pantalla completa al navegar entre todas las vistas. Use `Alt+F4` para cerrar el kiosco.

## Impresión directa de tickets minoristas

La aplicación web no puede enumerar, elegir ni configurar las impresoras de la computadora. Esa configuración pertenece a Windows, no al servidor `sada-csa.com`.

El acceso principal comprueba la impresora predeterminada cada vez que se abre:

- Si existe una impresora física predeterminada y se puede validar, Chrome o Edge imprime directamente.
- Si no hay una predeterminada, fue eliminada, es virtual o no puede validarse con seguridad, el navegador muestra su ventana normal para elegir una impresora.
- Microsoft Print to PDF, XPS, OneNote, Fax y los puertos `PORTPROMPT:` o `FILE:` nunca habilitan la impresión silenciosa.

La impresión adaptativa está activa de forma predeterminada. También se crean dos accesos auxiliares en el escritorio:

- **Sistema Pollos - Seleccionar impresora** abre siempre el sistema sin impresión silenciosa. Es la ruta de recuperación si el operador necesita elegir otro destino.
- **Configurar impresora - Sistema Pollos** abre directamente la configuración de impresoras de Windows.

Después de instalar, eliminar o cambiar la impresora predeterminada, cierre por completo el kiosco con `Alt+F4` y vuelva a abrirlo para que el launcher evalúe el nuevo estado.

### Establecer la térmica durante la instalación

Ejecute una sola vez, usando el nombre exacto con el que aparece la impresora en Windows:

```powershell
Add-Type -AssemblyName System.Drawing
[System.Drawing.Printing.PrinterSettings]::InstalledPrinters

.\scripts\Install-SistemaPollosKiosk.ps1 `
    -Browser Chrome `
    -PrinterName "NOMBRE EXACTO DE LA TERMICA" `
    -ShortcutName "Sistema Pollos - Impresion directa"
```

`-PrinterName` primero comprueba que el nombre corresponda exactamente a una impresora instalada, después solicita a Windows establecerla como predeterminada y confirma el resultado. Si cualquiera de esos pasos falla, la instalación se detiene y no selecciona otra impresora.

Si la térmica ya está configurada como predeterminada, omita `-PrinterName`:

```powershell
.\scripts\Install-SistemaPollosKiosk.ps1 `
    -Browser Chrome
```

Para desactivar por completo la impresión directa en el acceso principal:

```powershell
.\scripts\Install-SistemaPollosKiosk.ps1 -DirectPrint:$false
```

El launcher utiliza perfiles separados llamados `Direct` y `Normal`. Esto evita que un proceso de Chrome o Edge reutilice accidentalmente la configuración silenciosa cuando corresponde mostrar el selector. La primera vez que se active cada modo puede ser necesario iniciar sesión en `sada-csa.com`.

Si abre el sistema desde una pestaña común o desde la PWA instalada, el diálogo de impresión seguirá apareciendo; la impresión automática solamente funciona desde el acceso principal creado por el instalador.

Antes de usarlo en producción, ajuste en las preferencias de la impresora térmica el papel de 80 mm, escala de 100 %, una copia y márgenes mínimos. La impresión directa usa la impresora predeterminada y sus preferencias actuales.

Si Windows tiene activada la opción **Permitir que Windows administre mi impresora predeterminada**, es recomendable desactivarla para impedir cambios automáticos.

Windows mantiene una sola impresora predeterminada por usuario. Si dos módulos deben imprimir en dos impresoras diferentes dentro de la misma sesión de Windows, este acceso no puede dirigir cada ticket a una impresora distinta; use cuentas de Windows separadas o una solución de impresión local que permita seleccionar la impresora por módulo.

## Inicio automático opcional

Presione `Win+R`, escriba `shell:startup` y copie allí el acceso directo creado. De esta forma Windows abrirá el sistema al iniciar la sesión del operador.

El modo kiosco del navegador evita que aparezca su interfaz. Si además necesita impedir el acceso al escritorio, configure una cuenta de Windows dedicada con **Acceso asignado**.

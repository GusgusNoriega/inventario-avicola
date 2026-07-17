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

## Crear el acceso directo kiosco

Abra PowerShell en la carpeta del proyecto y ejecute:

```powershell
.\scripts\Install-SistemaPollosKiosk.ps1 -Url "https://sistema.ejemplo.com"
```

Para usar Google Chrome:

```powershell
.\scripts\Install-SistemaPollosKiosk.ps1 -Url "https://sistema.ejemplo.com" -Browser Chrome
```

El acceso se crea en el escritorio y conserva la pantalla completa al navegar entre todas las vistas. Use `Alt+F4` para cerrar el kiosco.

## Inicio automático opcional

Presione `Win+R`, escriba `shell:startup` y copie allí el acceso directo creado. De esta forma Windows abrirá el sistema al iniciar la sesión del operador.

El modo kiosco del navegador evita que aparezca su interfaz. Si además necesita impedir el acceso al escritorio, configure una cuenta de Windows dedicada con **Acceso asignado**.

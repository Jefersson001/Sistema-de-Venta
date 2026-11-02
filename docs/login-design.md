# Diseño del acceso Vent-As

La vista de login sigue la referencia proporcionada: escena comercial oscura, marca y módulos a la izquierda; formulario claro con campos redondeados y degradado a la derecha. El diseño compacto se adapta a escritorio y móvil sin fijar ni recortar el contenido del formulario. La ayuda de contraseña explica el restablecimiento por el administrador. La autenticación y la duración de sesión mantienen sus reglas actuales.

Recurso generado: [img/login-business-scene.png](../img/login-business-scene.png). Se utilizó la herramienta integrada `image_gen`, sin CLI. La escena es decorativa; el logo existente, los textos, los iconos de módulos y el formulario se presentan mediante HTML/CSS/SVG.

Prompt final utilizado:

> Create a polished photographic/3D marketing background asset for the LEFT HALF of a compact business software sign-in page. Landscape 4:3 composition. Deep midnight navy #17152F and rich purple #6425D9, violet neon accents, cinematic softly blurred retail store shelves in background. A realistic modern silver laptop in 3/4 view occupies bottom left through center, open screen shows an abstract clean white ERP dashboard with 8 tiny colorful module cards in two rows, no readable text or logos on the screen. A small metal shopping cart with a few cardboard boxes sits in front lower left, realistic scale. Leave top 25% mostly dark clean negative space for an actual HTML brand logo and bottom 12% dark for HTML slogan. Keep rightmost 22% dark soft purple negative space for HTML list of modules. Laptop sharp with stylish lavender lighting, premium cohesive digital business illustration, restrained glow. No words, no lettering, no watermarks, no logos. Generate the scene asset only, NOT the full login page, no login form, no outer frame.

Comprobación visual y de dimensiones en Chrome: 1440×900, 1366×600, 1280×720, 1024×600, 390×664, 360×640 y 320×568. También se comprobó mostrar/ocultar contraseña y desplegar la ayuda de recuperación.

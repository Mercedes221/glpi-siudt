# Importador de activos SIUDT

Complemento para GLPI 10.0 que importa computadoras, monitores, impresoras y periféricos desde archivos CSV.

## Uso

1. Activa el complemento `siudt_front` desde **Configuración > Complementos**.
2. Concede al usuario permisos de creación en los tipos de activos que vaya a importar.
3. En **Herramientas > Importar activos SIUDT**, descarga la plantilla, completa sus filas y valida el archivo.
4. Revisa los errores y confirma la importación cuando todas las filas sean válidas.

El importador acepta hasta 500 filas y 5 MB por archivo. El número de serie es obligatorio y debe ser único entre los activos admitidos, incluidos los que ya existen en GLPI. Los encabezados opcionales son `codigo_inventario`, `estado`, `ubicacion` y `comentarios`. Los estados y ubicaciones indicados deben existir previamente en GLPI.

| Columna | Obligatoria | Descripción |
|---|---:|---|
| `tipo` | Sí | `computadora`, `monitor`, `impresora` o `periferico` |
| `nombre` | Sí | Nombre visible del activo |
| `numero_serie` | Sí | Serie única usada para detectar duplicados |
| `codigo_inventario` | No | Código institucional; se guarda como número de inventario de GLPI |
| `estado` | No | Nombre de un estado ya configurado en GLPI |
| `ubicacion` | No | Nombre de una ubicación ya configurada en GLPI |
| `comentarios` | No | Comentario del activo |

El CSV debe estar codificado en UTF-8. Se aceptan separadores de punto y coma o coma. Los activos se crean usando las clases nativas de GLPI, por lo que respetan los permisos y el historial de cambios de la plataforma.

# Importación CSV de activos

El menú **Herramientas > Importar activos (CSV)** ofrece una carga guiada para
los tipos de activo que GLPI 12 publica en `asset_types`, incluidos equipos,
periféricos, licencias, certificados y activos personalizados registrados ahí.
Esto cubre los tipos de inventario habilitados como activos en GLPI; no incluye
módulos separados como consumibles, cartuchos ni software instalado.

## Uso

1. Asegura que los fabricantes, modelos, ubicaciones, estados y demás valores de
   listas que se vayan a usar ya existan en GLPI.
2. Prepara un CSV con encabezados en la primera fila. Se detectan separadores
   coma, punto y coma y tabulación; se aceptan archivos UTF-8 con o sin BOM.
3. En **Herramientas > Importar activos (CSV)**, selecciona el tipo de activo y
   carga el archivo.
4. Relaciona cada columna con un campo GLPI. Las columnas no asignadas se
   ignoran. Para los campos de listas relacionadas, se busca el valor existente
   por nombre dentro del ámbito visible de la entidad activa.
5. Revisa la vista previa. Las filas con relaciones inexistentes o ambiguas se
   reportan y no se crean. Confirma la importación para crear las filas válidas.

El importador respeta los permisos de creación del perfil y crea los registros
en la entidad activa. Limita la carga a 2 MB y 5,000 filas. Los activos se
crean en GLPI mediante sus clases nativas; los errores de validación propios de
GLPI se deben revisar en sus mensajes o registros. No actualiza activos
existentes y marca como error las filas cuyo número de serie o de inventario
se repita en el CSV o ya exista en la entidad activa.

La pantalla cubre los campos escalares expuestos por las opciones de búsqueda
del tipo de activo. No importa relaciones de varios elementos, componentes de
hardware, adjuntos ni estructuras anidadas desde una sola fila. Esos casos
requieren una plantilla específica para el tipo de activo.

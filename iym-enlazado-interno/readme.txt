=== IYM Enlazado Interno ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Enlazado interno automático por palabras clave.

== Description ==

* Reglas: palabras clave -> URL de destino, con límite por entrada, nofollow, pestaña nueva y opción de distinguir mayúsculas.
* Los enlaces se insertan al mostrar el contenido (filtro `the_content`), así que la base de datos no se modifica y desactivar el plugin los quita todos.
* Nunca enlaza dentro de enlaces, encabezados, código, scripts ni botones.
* Evita autoenlaces y URLs que la entrada ya enlaza a mano.
* Límite global por entrada y por URL.
* Si el destino es una entrada del sitio, sigue el permalink aunque cambie el slug.
* Importación y exportación en CSV.
* Casilla por entrada para desactivar el enlazado.

== Changelog ==

= 0.1.0 =
* Primera versión.

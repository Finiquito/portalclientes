# Narración · Video 1 «Lo que ve mi cliente» (64,6 s)

Un clip de audio por bloque (ElevenLabs → `01.mp3` … `10.mp3`). Cada clip se monta en su segundo de inicio.

| Clip | Empieza | Dura máx. | Texto |
|---|---|---|---|
| 01 | 0:00 | 4 s | Hace dos años que trabajo como freelancer. Y cuando empecé a sumar clientes… ¡caos! |
| 02 | 0:04 | 8 s | Probé con Tareas de Google: me quedó chico. Probé con Jira: pasaba más tiempo manteniéndolo que trabajando. |
| 03 | 0:12 | 7 s | Así que usé mi tiempo extra en armar algo simple. Mira: mi clienta recibe la grilla tal cual se verá en Instagram. |
| 04 | 0:20,4 | 5 s | Pasa las láminas como en el celular… masa madre… ya me dio hambre. |
| 05 | 0:25,4 | 7 s | ¿Le gusta? ¡Le encanta! Epa… y lo aprueba con un toque. |
| 06 | 0:32,4 | 10 s | Antes me mandaba un audio de tres minutos. Ahora… marca el punto exacto en la imagen. |
| 07 | 0:42,4 | 8 s | Y pide el cambio ahí mismo. ¿Y si el pan sale más grande? Clarito, sin vueltas. |
| 08 | 0:50,4 | 3 s | La story, aprobada. |
| 09 | 0:53,4 | 7 s | Y a mí me llega todo junto: qué aprobó, qué cambia y dónde. Simple, para no gastar mi tiempo en ordenar. |
| 10 | 1:00,4 | 4 s | Puedes inscribirte para la beta gratis. Pruébalo y me cuentas cómo te va. |

Tiempos de la **versión editada con movimiento** (64,6 s; ver `remotion/README.md`). Qué se ve en cada momento:

- 0:00–0:12 · clips 01 y 02: los post-its van llenando la pantalla; el «¡CAOS!» cae en el segundo 3,4 y los post-its de «Tareas de Google» y «Jira» aparecen en los segundos 5,1 y 7,9.
- 0:12 · clip 03: todo se ordena y aparece la portada; la grilla de Josefa llega en el segundo 16,8.
- Del clip 04 al 09: cada bloque empieza justo cuando cambia la pantalla.

Para montar los MP3 sobre el video: `bash dev/videos/remotion/montar-narracion.sh video.mp4 <carpeta-con-01.mp3…10.mp3> salida.mp4`.

Si el clip 03 pasa de 7 s, quitar «Mira:». Si el 10 pasa de 4 s, se puede alargar la tarjeta final en `remotion/src/data.ts` (`DURACION`).

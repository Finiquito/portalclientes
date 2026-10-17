# Ramas y versiones

Hay **dos líneas de producto** en este repo. No se mezclan en la numeración.

| Línea | Rama | Para quién | Versión | Ejemplo |
|---|---|---|---|---|
| **Personal** | `claude/youthful-gates-tjxgp0` (la rama por defecto) | Tu portal en `proyectos.richgt.com` | `0.36.x`, `0.37`… | `0.36.0` |
| **Beta** | `beta` | Los portales de las agencias beta (`agencia.usaprisma.com`) | `0.N.M-beta`, que se lee «beta 0.N» | `0.10.0-beta` = **beta 0.10** |

- La numeración de la beta es **independiente** de la personal: «beta 0.10» no es «anterior» a la 0.36.
- El número técnico lleva `-beta` (`0.10.0-beta`) para que ningún zip de beta se confunda con uno personal ni se instale por error en tu portal.
- Los zips de la beta se llaman `portal-plugin-v0.10.0-beta.zip`.

## Qué va en cada línea

- **Personal:** mejoras de tu uso diario del portal. Es la rama donde sigues trabajando.
- **Beta:** todo lo que necesita ser distinto para repartir a otras agencias: código compartido por enlace (`releases/`), correo de Prisma, prueba de IA, onboarding, tickets, heartbeat, `docs/protocolo-beta.md`.
- Lo de la beta **no hace nada** si no hay configuración compartida (`PORTAL_SECRETOS_DIR`): una instalación normal se comporta igual que la personal.

## Cómo fluyen los cambios

```
personal (0.36.x) ──merge──▶ beta        las mejoras que sirven a todos pasan a la beta
beta ──────────────✗─────── personal      lo propio de la beta no vuelve (salvo que lo pidas)
```

1. Trabajas y publicas en la rama personal, como siempre.
2. Cuando quieras que la beta reciba lo nuevo: `git checkout beta && git merge claude/youthful-gates-tjxgp0`, se corren las pruebas y se saca una beta nueva (`0.11.0-beta`…).
3. Los arreglos hechos primero en la beta que también sirvan a tu portal se llevan con `git cherry-pick`.

Nunca se hace merge de `beta` hacia la personal sin decidirlo antes: traería el correo de Prisma y los cambios de carpeta de archivos.

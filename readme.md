# Sistema de Inventario de Laboratorio — Docker Compose (5 Servicios)

**Asignación Individual del Dominio:** Último dígito `6` → **Inventario de Laboratorio** (Entidad principal: `Equipo`).

Aplicación web distribuida en cinco contenedores orquestados con **Docker Compose** y comunicados a través de una red interna aislada (`app_network`).

---

## 1. Arquitectura de Servicios

| Servicio | Imagen Base / Build | Puerto Interno | Puerto Host | Responsabilidad |
| :--- | :--- | :--- | :--- | :--- |
| `frontend` | `build: ./frontend` (`nginx:alpine`) | `80` | **`8081`** | Interfaz web estática y Proxy Inverso (`/api/` → `http://backend/`) |
| `backend` | `build: ./backend` (`php:8.3-apache`) | `80` | *Solo red interna* | API REST (`/equipos`, `/health`), integración PDO MariaDB, Redis y SMTP |
| `db` | `mariadb:11.4` | `3306` | *Solo red interna* | Persistencia relacional de registros en volumen nombrado `mariadb_data` |
| `redis` | `redis:7-alpine` | `6379` | *Solo red interna* | Caché de consultas (`equipos_cache`, TTL 30s), contador (`contador_consultas`) y persistencia AOF |
| `mailpit` | `axllent/mailpit:v1.21` | `1025` (SMTP) / `8025` (UI) | **`8025`** | Captura de correos de prueba en desarrollo |

> **Nota sobre Mailpit:** Mailpit es exclusivamente una herramienta de pruebas y desarrollo local (servidor SMTP sandbox). Captura los mensajes enviados al puerto `1025` para visualizarlos en `http://localhost:8025`, pero **no entrega correos reales hacia Internet**.

---

## 2. Cómo se Resuelven los Nombres `db`, `redis`, `mailpit` y `backend` (DNS Interno)

Al declarar la red definida por el usuario `app_network` (`driver: bridge`) en `compose.yaml`, Docker inicia un **servidor DNS interno embebido** (`127.0.0.11`) dentro de cada contenedor conectado a esa red:
- Cuando el navegador solicita `http://localhost:8081/api/equipos`, el contenedor `frontend` (Nginx) intercepta `/api/` y resuelve el nombre `backend` mediante el DNS interno de Docker hacia la IP privada del contenedor PHP/Apache en `app_network`.
- De igual forma, el contenedor `backend` resuelve los nombres de servicio `db` (puerto `3306`), `redis` (puerto `6379`) y `mailpit` (puerto `1025`) directamente a sus direcciones IP internas dentro de `app_network`, sin usar `localhost` ni exponer esos puertos al sistema anfitrión.

---

## 3. Mejoras Individuales Implementadas (Sección 7 del Documento)

1. **Filtro de búsqueda (`?buscar=termino`):** Permite filtrar equipos por nombre, marca, número de serie o ubicación directamente desde la interfaz o consultando `/api/equipos?buscar=Olympus`.
2. **Exportación JSON (`?export=json`):** Botón **Exportar JSON** que descarga el inventario completo en un archivo `inventario_equipos.json` generado por el backend (`/api/equipos?export=json`).

---

## 4. Requisitos e Instrucciones de Ejecución

1. Crear el archivo `.env` a partir de `.env.example`:
   ```powershell
   Copy-Item .env.example .env
   ```
2. Construir y levantar los cinco servicios en segundo plano:
   ```powershell
   docker compose up -d --build
   ```
3. URLs de acceso en el navegador:
   - **Aplicación Web (Frontend):** `http://localhost:8081`
   - **Bandeja de Correos (Mailpit):** `http://localhost:8025`

---

## 5. Comandos de Evidencia y las 10 Pruebas Obligatorias

```powershell
# 1. Validación YAML
docker compose config

# 2. Estado de los 5 servicios, health checks y aislamiento de puertos
docker compose ps

# 3. Imágenes utilizadas y construidas
docker compose images

# 4. Consultar datos directamente en la tabla equipos de MariaDB
docker compose exec db mariadb -u lab_admin -pLabInv_2026_Secret! -D inventario_db -e "SELECT * FROM equipos;"

# 5. Claves, contador y TTL en Redis
docker compose exec redis redis-cli KEYS "*"
docker compose exec redis redis-cli GET contador_consultas
docker compose exec redis redis-cli TTL equipos_cache

# 6. Inspección de red y volumen
docker network inspect proyecto-gomez-valencia-rogelio-isaac_app_network
docker volume inspect proyecto-gomez-valencia-rogelio-isaac_mariadb_data

# 7. Prueba de Persistencia (Recrear db sin eliminar volumen)
docker compose rm -sf db
docker compose up -d db

# 8. Prueba de Recuperación (Detener e iniciar backend)
docker compose stop backend
docker compose start backend
```

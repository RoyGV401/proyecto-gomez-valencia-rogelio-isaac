const API_BASE = '/api';

const alertBox         = document.getElementById('alert-box');
const equipoForm       = document.getElementById('equipo-form');
const equipoIdInput    = document.getElementById('equipo-id');
const nombreInput      = document.getElementById('nombre');
const marcaInput       = document.getElementById('marca');
const serieInput       = document.getElementById('numero_serie');
const ubicacionInput   = document.getElementById('ubicacion');
const estadoSelect     = document.getElementById('estado');
const formTitle        = document.getElementById('form-title');
const submitBtn        = document.getElementById('submit-btn');
const cancelEditBtn    = document.getElementById('cancel-edit-btn');
const refreshBtn       = document.getElementById('refresh-btn');
const checkHealthBtn   = document.getElementById('check-health-btn');
const searchInput      = document.getElementById('search-input');
const searchBtn        = document.getElementById('search-btn');
const exportBtn        = document.getElementById('export-btn');
const tbody            = document.getElementById('equipos-tbody');
const totalCountEl     = document.getElementById('total-count');

const healthText       = document.getElementById('health-text');
const cacheSourceBadge = document.getElementById('cache-source-badge');
const cacheTtlValue    = document.getElementById('cache-ttl-value');
const counterValue     = document.getElementById('counter-value');

function showAlert(message, type = 'success') {
    alertBox.textContent = message;
    alertBox.className = `alert ${type}`;
    alertBox.classList.remove('hidden');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

async function checkHealth(showBanner = false) {
    try {
        const res = await fetch(`${API_BASE}/health`);
        const data = await res.json();

        if (res.ok && data.status === 'healthy') {
            healthText.textContent = 'MariaDB OK | Redis OK';
            if (showBanner) {
                showAlert(
                    `Estado saludable (HTTP ${res.status}): MariaDB conectado y Redis activo (contador_consultas = ${data.checks.redis.contador_consultas}).`,
                    'success'
                );
            }
        } else {
            healthText.textContent = 'Error en servicios';
            if (showBanner) {
                showAlert(`Error de estado (HTTP ${res.status}): ${JSON.stringify(data.checks)}`, 'error');
            }
        }
    } catch (err) {
        healthText.textContent = 'Sin conexion';
        if (showBanner) {
            showAlert('Error al conectar con /api/health: El servicio backend no responde.', 'error');
        }
    }
}

async function loadEquipos(searchTerm = '') {
    try {
        const query = searchTerm ? `?buscar=${encodeURIComponent(searchTerm)}` : '';
        const res = await fetch(`${API_BASE}/equipos${query}`);
        const payload = await res.json();

        if (!res.ok) {
            throw new Error(payload.error || `Error HTTP ${res.status}`);
        }

        if (payload.cache_hit) {
            cacheSourceBadge.textContent = 'Redis Cache';
        } else {
            cacheSourceBadge.textContent = 'MariaDB';
        }

        cacheTtlValue.textContent = `${payload.cache_ttl_restante ?? 0}s`;
        counterValue.textContent = payload.contador_consultas ?? 0;

        const equipos = payload.data || [];
        totalCountEl.textContent = equipos.length;

        if (equipos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No hay equipos registrados.</td></tr>';
            return;
        }

        tbody.innerHTML = equipos.map((eq) => {
            return `
                <tr>
                    <td>${eq.id}</td>
                    <td>${escapeHtml(eq.nombre)}</td>
                    <td>${escapeHtml(eq.marca)}</td>
                    <td>${escapeHtml(eq.numero_serie)}</td>
                    <td>${escapeHtml(eq.ubicacion)}</td>
                    <td>${escapeHtml(eq.estado)}</td>
                    <td>
                        <div class="action-group">
                            <button type="button" class="btn btn-edit" data-edit='${JSON.stringify(eq).replace(/'/g, "&#39;")}'>
                                Editar
                            </button>
                            <button type="button" class="btn btn-delete" data-delete-id="${eq.id}" data-delete-name="${escapeHtml(eq.nombre)}">
                                Eliminar
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Error al cargar el inventario.</td></tr>';
        showAlert(`Error al consultar /api/equipos: ${err.message}`, 'error');
        checkHealth(false);
    }
}

function resetForm() {
    equipoForm.reset();
    equipoIdInput.value = '';
    formTitle.textContent = 'Registrar Equipo';
    submitBtn.textContent = 'Guardar';
    cancelEditBtn.classList.add('hidden');
}

function startEdit(equipo) {
    equipoIdInput.value = equipo.id;
    nombreInput.value = equipo.nombre;
    marcaInput.value = equipo.marca;
    serieInput.value = equipo.numero_serie;
    ubicacionInput.value = equipo.ubicacion;
    estadoSelect.value = equipo.estado;

    formTitle.textContent = `Editar Equipo ID ${equipo.id}`;
    submitBtn.textContent = 'Actualizar';
    cancelEditBtn.classList.remove('hidden');
    nombreInput.focus();
}

equipoForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const id = equipoIdInput.value.trim();
    const isEdit = id !== '';
    const url = isEdit ? `${API_BASE}/equipos/${id}` : `${API_BASE}/equipos`;
    const method = isEdit ? 'PUT' : 'POST';

    const bodyData = {
        nombre: nombreInput.value.trim(),
        marca: marcaInput.value.trim(),
        numero_serie: serieInput.value.trim(),
        ubicacion: ubicacionInput.value.trim() || 'Laboratorio General',
        estado: estadoSelect.value,
    };

    try {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bodyData),
        });
        const result = await res.json();

        if (!res.ok) {
            throw new Error(result.error || `Error HTTP ${res.status}`);
        }

        if (isEdit) {
            showAlert(`Equipo ID ${id} actualizado. Cache de Redis invalidada.`, 'success');
        } else {
            const mailNote = result.notificacion_enviada
                ? 'Correo enviado a Mailpit.'
                : 'Registrado sin confirmacion de correo.';
            showAlert(`Equipo "${result.data.nombre}" registrado. Cache invalidada. ${mailNote}`, 'success');
        }

        resetForm();
        await loadEquipos();
    } catch (err) {
        showAlert(`Error al guardar: ${err.message}`, 'error');
    }
});

cancelEditBtn.addEventListener('click', () => {
    resetForm();
});

refreshBtn.addEventListener('click', async () => {
    searchInput.value = '';
    await loadEquipos('');
    await checkHealth(false);
});

searchBtn.addEventListener('click', async () => {
    await loadEquipos(searchInput.value.trim());
});

exportBtn.addEventListener('click', () => {
    window.location.href = `${API_BASE}/equipos?export=json`;
});

checkHealthBtn.addEventListener('click', async () => {
    await checkHealth(true);
});

tbody.addEventListener('click', async (e) => {
    const editBtn = e.target.closest('[data-edit]');
    if (editBtn) {
        const equipo = JSON.parse(editBtn.getAttribute('data-edit'));
        startEdit(equipo);
        return;
    }

    const deleteBtn = e.target.closest('[data-delete-id]');
    if (deleteBtn) {
        const id = deleteBtn.getAttribute('data-delete-id');
        const nombre = deleteBtn.getAttribute('data-delete-name');

        if (!confirm(`Desea eliminar el equipo "${nombre}" (ID ${id})?`)) {
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/equipos/${id}`, { method: 'DELETE' });
            const result = await res.json();

            if (!res.ok) {
                throw new Error(result.error || `Error HTTP ${res.status}`);
            }

            showAlert(`Equipo "${nombre}" eliminado. Cache de Redis invalidada.`, 'success');
            if (equipoIdInput.value === String(id)) {
                resetForm();
            }
            await loadEquipos();
        } catch (err) {
            showAlert(`Error al eliminar: ${err.message}`, 'error');
        }
    }
});

document.addEventListener('DOMContentLoaded', async () => {
    await checkHealth(false);
    await loadEquipos();
});

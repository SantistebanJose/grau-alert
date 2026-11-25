(() => {
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  const state = { csrf: '', user: null, students: [], scanner: null, currentToken: '', scanTimer: null, dbReady: false };
  const toast = (message, error = false) => {
    const el = $('#toast'); el.textContent = message; el.classList.toggle('error', error); el.classList.add('show');
    clearTimeout(toast.timer); toast.timer = setTimeout(() => el.classList.remove('show'), 3400);
  };
  async function request(action, { method = 'GET', data = null, query = '' } = {}) {
    const url = `api.php?action=${encodeURIComponent(action)}${query}`;
    const options = { method, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (data) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify({ ...data, csrf: state.csrf }); }
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({}));
    if (result.csrf) state.csrf = result.csrf;
    if (!response.ok) throw new Error(result.error || 'No se pudo completar la solicitud.');
    return result;
  }
  const initials = (name = '') => name.trim().split(/\s+/).slice(0, 2).map(x => x[0] || '').join('').toUpperCase();
  const esc = (value = '') => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fullName = s => String(s.nombre_completo || `${s.nombres || ''} ${s.apellidos || ''}`).trim();
  const whatsappPhone = value => {
    const digits = String(value || '').replace(/\D/g, '');
    if (digits.length === 9) return `51${digits}`;
    if (digits.startsWith('0') && digits.length === 10) return `51${digits.slice(1)}`;
    return digits;
  };

  async function boot() {
    try {
      const result = await request('state');
      state.csrf = result.csrf;
      state.dbReady = true;
      if (result.user) enterApp(result.user);
      else if (result.needs_setup) { $('#app-loading').classList.add('hidden'); $('#auth-screen').classList.remove('hidden'); $('#login-form').classList.add('hidden'); $('#setup-form').classList.remove('hidden'); if (!result.setup_key_required) $('#setup-key-label').classList.add('hidden'); }
      else if (window.INITIAL_PAGE !== 'login') window.location.replace('login.php');
    } catch (e) {
      $('#app-loading').classList.add('hidden');
      $('#auth-screen').classList.remove('hidden');
      $('#connection-error').textContent = `${e.message} En XAMPP, habilita extension=pdo_pgsql en php.ini, configura las variables de conexión en Apache y reinícialo.`;
      $('#connection-error').classList.remove('hidden');
      $('#login-form button[type="submit"]').disabled = true;
      toast(e.message, true);
    }
  }
  function enterApp(user) {
    state.user = user;
    $('#auth-screen').classList.add('hidden'); $('#app-loading').classList.add('hidden'); $('#app').classList.remove('hidden');
    if (window.INITIAL_PAGE === 'login') { window.location.replace(user.rol === 'apoderado' ? 'familia.php' : 'index.php'); return; }
    $('#user-name').textContent = `${user.nombres} ${user.apellidos}`;
    $('#greeting-name').textContent = user.nombres.split(' ')[0];
    $('#user-role').textContent = user.rol;
    $('#today-label').textContent = new Intl.DateTimeFormat('es-PE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' }).format(new Date());
    const family = user.rol === 'apoderado';
    if (user.rol !== 'administrador') $('#new-student-btn').classList.add('hidden');
    $$('.admin-only').forEach(el => el.classList.toggle('hidden', user.rol !== 'administrador'));
    $$('.report-only').forEach(el => el.classList.toggle('hidden', !['administrador', 'tutor'].includes(user.rol)));
    $$('.family-only').forEach(el => el.classList.toggle('hidden', !family));
    if (family) {
      $$('.page').forEach(el => { if (el.id !== 'page-family') el.classList.add('hidden'); });
      $$('.nav-link').forEach(el => { if (!el.classList.contains('family-only')) el.classList.add('hidden'); });
      $('#family-name').textContent = user.nombres.split(' ')[0];
      setPage('family');
    } else {
      let initial = window.INITIAL_PAGE || 'dashboard';
      if (user.rol === 'auxiliar' && ['community', 'calendar', 'reports', 'alerts', 'family'].includes(initial)) initial = 'dashboard';
      if (user.rol === 'tutor' && ['community', 'calendar', 'family'].includes(initial)) initial = 'dashboard';
      if (user.rol !== 'apoderado' && initial === 'family') initial = 'dashboard';
      setPage(initial);
    }
  }
  async function authSubmit(form, action) {
    if (!state.dbReady) { toast('Primero debe quedar activa la conexión con PostgreSQL.', true); return; }
    const data = Object.fromEntries(new FormData(form).entries());
    try { const result = await request(action, { method: 'POST', data }); enterApp(result.user); toast(action === 'setup' ? 'Administrador creado.' : 'Sesión iniciada.'); }
    catch (e) { toast(e.message, true); }
  }
  $('#login-form').addEventListener('submit', e => { e.preventDefault(); authSubmit(e.currentTarget, 'login'); });
  $('#setup-form').addEventListener('submit', e => { e.preventDefault(); authSubmit(e.currentTarget, 'setup'); });
  $('#logout-btn').addEventListener('click', async () => { try { await request('logout', { method: 'POST', data: {} }); location.href = 'login.php'; } catch (e) { toast(e.message, true); } });

  function setPage(name) {
    $$('.page').forEach(p => p.classList.add('hidden'));
    $(`#page-${name}`).classList.remove('hidden');
    $$('.nav-link').forEach(b => b.classList.toggle('active', b.dataset.page === name));
    $('#page-title').textContent = ({ dashboard: 'Resumen', students: 'Estudiantes', scanner: 'Marcar asistencia', community: 'Comunidad escolar', calendar: 'Calendario escolar', reports: 'Reportes', alerts: 'Avisos', family: 'Portal familiar' })[name];
    if (name === 'students') { loadStudents(); loadGrades(); }
    if (name === 'dashboard') loadDashboard();
    if (name === 'community') loadCommunity();
    if (name === 'calendar') loadCalendar();
    if (name === 'reports') loadReport();
    if (name === 'alerts') loadAlerts();
    if (name === 'family') loadFamily();
    if (name === 'scanner') startScanner(); else stopScanner();
  }
  $$('[data-goto]').forEach(b => b.addEventListener('click', () => { const links = { dashboard: 'index.php', students: 'estudiantes.php', scanner: 'asistencia.php', community: 'comunidad.php', calendar: 'calendario.php', reports: 'reportes.php', alerts: 'avisos.php', family: 'familia.php' }; location.href = links[b.dataset.goto] || 'index.php'; }));
  $('#mobile-menu-toggle').addEventListener('click', () => {
    const panel = $('#mobile-menu-panel');
    const open = panel.classList.toggle('open');
    $('.sidebar').classList.toggle('mobile-open', open);
    $('#mobile-menu-backdrop').classList.toggle('visible', open);
    $('#mobile-menu-toggle').setAttribute('aria-expanded', String(open));
  });
  $('#mobile-menu-backdrop').addEventListener('click', () => {
    $('#mobile-menu-panel').classList.remove('open');
    $('.sidebar').classList.remove('mobile-open');
    $('#mobile-menu-backdrop').classList.remove('visible');
    $('#mobile-menu-toggle').setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('click', event => {
    const panel = $('#mobile-menu-panel');
    if (!panel || !panel.classList.contains('open') || panel.contains(event.target) || $('#mobile-menu-toggle').contains(event.target)) return;
    panel.classList.remove('open'); $('.sidebar').classList.remove('mobile-open'); $('#mobile-menu-backdrop').classList.remove('visible'); $('#mobile-menu-toggle').setAttribute('aria-expanded', 'false');
  });
  $$('[data-close]').forEach(b => b.addEventListener('click', () => $(`#${b.dataset.close}`).close()));

  async function loadDashboard() {
    try {
      const [students, log] = await Promise.all([request('students'), request('attendance_today')]);
      $('#stat-students').textContent = students.students.length;
      $('#stat-present').textContent = log.attendance.length;
      const last = log.attendance[0];
      $('#stat-last').textContent = last ? new Date(last.hora_ingreso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' }) : '—';
      $('#stat-last-name').textContent = last ? fullName(last) : 'Esperando asistencia';
      const rows = $('#attendance-rows');
      rows.innerHTML = log.attendance.map(a => `<tr><td><div class="student-cell">${photo(a.foto_url, fullName(a))}<div><b>${esc(fullName(a))}</b><small>DNI ${esc(a.dni || '—')} · Código ${esc(a.codigo)}</small></div></div></td><td>${esc(a.grado || 'Sin grado')}</td><td>${new Date(a.hora_ingreso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' })}</td><td><span class="badge ${a.estado === 'tardanza' ? 'late-badge' : ''}">${a.estado === 'tardanza' ? 'Tardanza' : 'Presente'}</span></td></tr>`).join('');
      $('#attendance-empty').classList.toggle('hidden', log.attendance.length > 0);
    } catch (e) { toast(e.message, true); }
  }
  function photo(url, name, cls = 'student-photo') { return url ? `<img class="${cls}" src="${esc(url)}" alt="Foto de ${esc(name)}" referrerpolicy="no-referrer" onerror="this.outerHTML='<span class=\'${cls}\'>${esc(initials(name))}</span>'">` : `<span class="${cls}">${esc(initials(name))}</span>`; }
  const studentFilter = { level: 'primaria', grade: 'todos' };
  const gradeOrder = ['primero', 'segundo', 'tercero', 'cuarto', 'quinto', 'sexto'];
  function renderStudentRows() {
    const inLevel = state.students.filter(s => (s.nivel || '').toLowerCase() === studentFilter.level);
    const grades = [...new Set(inLevel.map(s => s.grado).filter(Boolean))].sort((a, b) => {
      const ga = (a.split(/[·-]/).pop() || '').trim().toLowerCase(), gb = (b.split(/[·-]/).pop() || '').trim().toLowerCase();
      return gradeOrder.indexOf(ga) - gradeOrder.indexOf(gb);
    });
    $('#student-grade-tabs').innerHTML = `<button type="button" class="student-tab ${studentFilter.grade === 'todos' ? 'active' : ''}" data-grade="todos">Todos</button>` + grades.map(g => {
      const label = g.split(/[·-]/).pop().trim();
      return `<button type="button" class="student-tab ${studentFilter.grade === g ? 'active' : ''}" data-grade="${esc(g)}">${esc(label.charAt(0) + label.slice(1).toLowerCase())}</button>`;
    }).join('');
    $$('#student-grade-tabs [data-grade]').forEach(b => b.addEventListener('click', () => { studentFilter.grade = b.dataset.grade; renderStudentRows(); }));
    const visible = inLevel.filter(s => studentFilter.grade === 'todos' || s.grado === studentFilter.grade);
    $('#student-count').textContent = `${visible.length} estudiante${visible.length === 1 ? '' : 's'}`;
    const admin = state.user?.rol === 'administrador';
    $('#student-rows').innerHTML = visible.map(s => `<tr><td><div class="student-cell">${photo(s.foto_url, fullName(s))}<div><b>${esc(fullName(s))}</b></div></div></td><td>${esc(s.dni || '—')}</td><td>${esc(s.codigo)}</td><td>${esc(s.grado || 'Sin grado')}</td><td><button class="qr-action" data-card="${esc(s.id)}">Ver carné QR</button></td>${admin ? `<td><div class="row-actions"><button class="qr-action" data-edit-student="${esc(s.id)}">Editar</button><button class="qr-action danger-action" data-delete-student="${esc(s.id)}">Eliminar</button></div></td>` : ''}</tr>`).join('');
    $('#students-empty').classList.toggle('hidden', visible.length > 0);
    $$('[data-card]').forEach(b => b.addEventListener('click', () => showCard(state.students.find(s => s.id === b.dataset.card))));
    $$('[data-edit-student]').forEach(b => b.addEventListener('click', () => openStudentEditor(state.students.find(s => s.id === b.dataset.editStudent))));
    $$('[data-delete-student]').forEach(b => b.addEventListener('click', () => deactivateStudent(b.dataset.deleteStudent)));
  }
  async function loadStudents(search = '') {
    try { const result = await request('students', { query: `&q=${encodeURIComponent(search)}` }); state.students = result.students; renderStudentRows(); }
    catch (e) { toast(e.message, true); }
  }
  $$('#student-level-tabs [data-level]').forEach(b => b.addEventListener('click', () => {
    studentFilter.level = b.dataset.level; studentFilter.grade = 'todos';
    $$('#student-level-tabs [data-level]').forEach(tab => tab.classList.toggle('active', tab === b)); renderStudentRows();
  }));
  $('#student-search').addEventListener('input', e => { clearTimeout(loadStudents.timer); loadStudents.timer = setTimeout(() => loadStudents(e.target.value), 220); });
  async function loadGrades() {
    try { const result = await request('grades'); $('#grade-select').innerHTML = '<option value="">Seleccionar grado</option>' + result.grades.map(g => `<option value="${g.id}">${esc(g.nombre)}</option>`).join(''); }
    catch (e) { toast(e.message, true); }
  }
  let editingStudentId = null;
  async function openStudentEditor(student = null) {
    await loadGrades(); editingStudentId = student?.id || null;
    const form = $('#student-form'); form.reset();
    $('#student-dialog-title').textContent = student ? 'Editar estudiante' : 'Nuevo estudiante';
    $('#student-save-btn').textContent = student ? 'Guardar cambios' : 'Guardar estudiante';
    if (student) for (const key of ['dni', 'codigo', 'nombres', 'apellidos', 'grado_id', 'foto_url']) form.elements[key].value = student[key] ?? '';
    $('#student-dialog').showModal();
  }
  async function deactivateStudent(id) {
    const student = state.students.find(s => s.id === id);
    if (!student || !confirm(`¿Dar de baja a ${fullName(student)}? Se conservará su historial de asistencias.`)) return;
    try { await request('student_deactivate', { method: 'POST', data: { id } }); toast('Estudiante dado de baja; su historial se conserva.'); await loadStudents($('#student-search').value); }
    catch (e) { toast(e.message, true); }
  }
  $('#new-student-btn').addEventListener('click', () => openStudentEditor());
  $('#student-form').addEventListener('submit', async e => {
    e.preventDefault(); const data = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
      const result = editingStudentId
        ? await request('student_update', { method: 'POST', data: { ...data, id: editingStudentId } })
        : await request('student_create', { method: 'POST', data });
      const wasEditing = Boolean(editingStudentId); editingStudentId = null; e.currentTarget.reset(); $('#student-dialog').close();
      toast(wasEditing ? 'Cambios guardados.' : 'Estudiante registrado.');
      if (!wasEditing) showCard(result.student);
      await loadStudents($('#student-search').value); loadDashboard();
    }
    catch (err) { toast(err.message, true); }
  });
  let workbookImport = null;
  $('#import-excel-btn').addEventListener('click', () => { $('#excel-file').value = ''; $('#import-preview').innerHTML = ''; $('#import-status').textContent = ''; $('#import-confirm').disabled = true; workbookImport = null; $('#import-dialog').showModal(); });
  function normHeader(value) { return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase(); }
  function findHeaderRow(matrix, required) { return matrix.findIndex(row => { const headers = row.map(normHeader); return required.every(k => headers.includes(normHeader(k))); }); }
  function excelDate(value) {
    if (value instanceof Date && !Number.isNaN(value.valueOf())) return value.toISOString().slice(0, 10);
    const text = String(value || '').trim();
    const match = text.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
    if (match) return `${match[1]}-${match[2].padStart(2, '0')}-${match[3].padStart(2, '0')}`;
    if (/^\d+(\.\d+)?$/.test(text) && window.XLSX) { const parts = XLSX.SSF.parse_date_code(Number(text)); if (parts) return `${parts.y}-${String(parts.m).padStart(2, '0')}-${String(parts.d).padStart(2, '0')}`; }
    return '';
  }
  $('#excel-file').addEventListener('change', async e => {
    const file = e.target.files[0]; if (!file) return;
    $('#import-confirm').disabled = true; $('#import-status').textContent = 'Leyendo el archivo en este navegador…';
    try {
      if (!window.XLSX) throw new Error('No se cargó el lector de Excel. Revisa la conexión a internet y vuelve a intentar.');
      const book = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: true });
      const calendar = book.Sheets['Calendario_Escolar_2027'];
      const headerAliases = {
        dni: ['numero documento', 'dni', 'numero de documento', 'nro documento'],
        codigo: ['codigo estudiante', 'id estudiante', 'codigo de estudiante'],
        nombres: ['nombres', 'nombre'], fullName: ['apellidos y nombres', 'nombre completo'],
        paternal: ['apellido paterno'], maternal: ['apellido materno'],
        level: ['nivel'], grade: ['grado y seccion', 'grado'], section: ['seccion'],
        email: ['correo', 'email', 'correo estudiante', 'correo del estudiante'],
        perueducaLogin: ['inicio sesion', 'inicio sesion perueduca', 'inicio sesion perueduca si no'],
        phone: ['telefono apoderado', 'telefono del apoderado']
      };
      const headerNorm = value => normHeader(value).replace(/[¿?]/g, '').replace(/\s+/g, ' ').trim();
      const findColumn = (headers, key) => headers.findIndex(value => headerAliases[key].some(alias => headerNorm(alias) === headerNorm(value)));
      const students = [];
      let matchedSheets = 0;
      for (const sheetName of book.SheetNames) {
        const matrix = XLSX.utils.sheet_to_json(book.Sheets[sheetName], { header: 1, raw: false, defval: '' });
        const header = matrix.findIndex(row => {
          const cells = row.map(normHeader);
          const col = key => findColumn(cells, key);
          return col('dni') >= 0 && col('codigo') >= 0 && (col('fullName') >= 0 || (col('nombres') >= 0 && col('paternal') >= 0)) && (col('grade') >= 0 || col('level') >= 0);
        });
        if (header < 0) continue;
        matchedSheets++;
        const headers = matrix[header].map(normHeader);
        const col = key => findColumn(headers, key);
        const value = (row, key) => col(key) < 0 ? '' : String(row[col(key)] ?? '').trim();
        matrix.slice(header + 1).forEach((row, offset) => {
          const dni = value(row, 'dni').replace(/\D/g, '');
          const code = value(row, 'codigo');
          const givenNames = value(row, 'nombres');
          const paternal = value(row, 'paternal');
          const maternal = value(row, 'maternal');
          const oldFullName = value(row, 'fullName');
          const names = givenNames || oldFullName;
          const last = [paternal, maternal].filter(Boolean).join(' ');
          const fullName = oldFullName || [paternal, maternal, givenNames].filter(Boolean).join(' ');
          const rawGrade = value(row, 'grade');
          const level = value(row, 'level');
          const section = value(row, 'section');
          const grade = rawGrade ? [level, `${rawGrade}${section ? ` ${section}` : ''}`].filter(Boolean).join(' · ') : '';
          const loginText = normHeader(value(row, 'perueducaLogin'));
          const perueducaLogin = loginText === '' ? null : ['si', 'yes', 'true', '1'].includes(loginText);
          if (dni || code || fullName || rawGrade) students.push({ fila: `${sheetName}:${header + offset + 2}`, dni, codigo: code, nombres: names, apellidos: last, nombre_completo: fullName, grado: grade, nivel: level, correo_estudiante: value(row, 'email'), inicio_sesion_perueduca: perueducaLogin, telefono_apoderado: value(row, 'phone') });
        });
      }
      if (!matchedSheets) throw new Error('No encontré hojas con DNI, Código Estudiante, nombres y grado. Revisa que sean los Excel oficiales del padrón.');
      let calendarRows = [];
      if (calendar) {
        const matrix = XLSX.utils.sheet_to_json(calendar, { header: 1, raw: false, defval: '' });
        const header = findHeaderRow(matrix, ['Fecha', 'Estado Día', 'Motivo / Festividad / Conmemoración']);
        if (header >= 0) {
          const ch = matrix[header].map(normHeader), col = key => ch.indexOf(normHeader(key));
          calendarRows = matrix.slice(header + 1).map((row, offset) => {
            const stateValue = String(row[col('Estado Día')] || '').trim().toLowerCase();
            const states = { lectivo: 'lectivo', feriado: 'feriado', vacaciones: 'vacaciones', suspension: 'suspension' };
            const stateValueClean = stateValue.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const state = states[stateValueClean];
            const date = excelDate(row[col('Fecha')]);
            const event = String(row[col('Motivo / Festividad / Conmemoración')] || '').trim();
            if (!date || !state || !event) return null;
            const alarm = String(row[col('Interruptor Alarma')] || '').trim().toUpperCase() === 'ACTIVA';
            return { fila: header + offset + 2, fecha: date, estado: state, evento: event, alarma_activa: alarm };
          }).filter(Boolean);
        }
      }
      if (!students.length) throw new Error('No encontré estudiantes debajo de los encabezados.');
      workbookImport = { rows: students, calendar_rows: calendarRows };
      $('#import-status').textContent = `Se encontraron ${students.length} estudiantes en ${matchedSheets} hojas y ${calendarRows.length} fechas de calendario. Se guardarán DNI, código, nivel, correo y estado de sesión de PerúEduca. La contraseña del Excel se ignora.`;
      $('#import-preview').innerHTML = `<table><thead><tr><th>ESTUDIANTE</th><th>DNI</th><th>CÓDIGO ESTUDIANTE</th><th>NIVEL / GRADO</th></tr></thead><tbody>${students.slice(0, 6).map(s => `<tr><td>${esc(s.nombre_completo)}</td><td>${esc(s.dni)}</td><td>${esc(s.codigo)}</td><td>${esc(s.grado)}</td></tr>`).join('')}</tbody></table>${students.length > 6 ? `<p class="form-note">Y ${students.length - 6} estudiantes más.</p>` : ''}`;
      $('#import-confirm').disabled = false;
    } catch (err) { workbookImport = null; $('#import-status').textContent = err.message; toast(err.message, true); }
  });
  $('#import-confirm').addEventListener('click', async () => {
    if (!workbookImport) return;
    const button = $('#import-confirm'); button.disabled = true; button.textContent = 'Importando…';
    try {
      const result = await request('student_import', { method: 'POST', data: workbookImport });
      const s = result.resumen;
      $('#import-status').textContent = `Listo: ${s.nuevos} estudiantes nuevos, ${s.actualizados} actualizados, ${s.dias_calendario} fechas de calendario y ${s.omitidos} filas omitidas.`;
      toast('Importación completada.'); loadStudents($('#student-search').value); loadDashboard();
      if (result.errores.length) console.warn('Filas omitidas en la importación:', result.errores);
      setTimeout(() => $('#import-dialog').close(), 1400);
    } catch (err) { toast(err.message, true); }
    finally { button.disabled = false; button.textContent = 'Importar datos'; }
  });
  async function loadCommunity() {
    try {
      const [staff, guardians, students] = await Promise.all([request('staff'), request('guardians'), request('students')]);
      state.communityStudents = students.students;
      $('#staff-rows').innerHTML = staff.staff.map(u => `<tr><td>${esc(`${u.nombres} ${u.apellidos}`)}</td><td>${esc(u.correo)}</td><td>${esc(u.rol)}</td><td>${esc(u.telefono || '—')}</td></tr>`).join('');
      $('#guardian-rows').innerHTML = guardians.guardians.map(g => `<tr><td>${esc(`${g.nombres} ${g.apellidos}`)}</td><td>${esc(g.correo)}</td><td>${esc(`${g.estudiante_nombres} ${g.estudiante_apellidos}`)}</td><td>${esc(g.grado || '—')}</td><td>${esc(g.telefono || '—')}${g.contacto_principal ? ' · Principal' : ''}</td><td><button type="button" class="btn btn-ghost guardian-reset-password" data-guardian-id="${esc(g.id)}">Nueva clave</button></td></tr>`).join('');
      $$('.guardian-reset-password').forEach(button => button.addEventListener('click', async () => {
        if (!confirm('Se reemplazará la clave actual. ¿Generar una nueva clave temporal?')) return;
        button.disabled = true;
        try {
          const result = await request('guardian_reset_password', { method: 'POST', data: { guardian_id: button.dataset.guardianId } });
          $('#guardian-reset-email').textContent = result.correo;
          $('#guardian-reset-password').textContent = result.temporary_password;
          $('#guardian-reset-result').classList.remove('hidden');
          toast('Clave temporal generada. Cópiala y compártela con el apoderado.');
        } catch (error) { toast(error.message, true); }
        finally { button.disabled = false; }
      }));
      $('#guardian-student-search').value = ''; $('#guardian-student-select').value = ''; $('#guardian-student-picked').classList.add('hidden');
    } catch (e) { toast(e.message, true); }
  }
  function renderGuardianStudentResults(value) {
    const root = $('#guardian-student-results');
    const query = normHeader(value.trim());
    root.classList.remove('hidden'); $('#guardian-student-search').setAttribute('aria-expanded', 'true');
    if (query.length < 2) {
      root.innerHTML = '<div class="student-picker-hint">Escribe al menos 2 letras o números para buscar.</div>';
      return;
    }
    const matches = (state.communityStudents || []).filter(s => normHeader(`${fullName(s)} ${s.dni || ''} ${s.codigo || ''} ${s.grado || ''}`).includes(query)).slice(0, 12);
    root.innerHTML = matches.length ? matches.map(s => `<button type="button" class="student-picker-option" role="option" data-student-id="${esc(s.id)}"><b>${esc(fullName(s))}</b><small>DNI ${esc(s.dni || '—')} · ${esc(s.grado || 'Sin grado')}</small></button>`).join('') : '<div class="student-picker-hint">No se encontraron estudiantes.</div>';
  }
  function pickGuardianStudent(student) {
    if (!student) return;
    const search = $('#guardian-student-search');
    $('#guardian-student-select').value = student.id;
    search.value = `${fullName(student)} · DNI ${student.dni || '—'} · ${student.grado || 'Sin grado'}`;
    search.dataset.studentSelected = student.id;
    const picked = $('#guardian-student-picked');
    picked.innerHTML = `<span>Estudiante seleccionado</span><b>${esc(fullName(student))}</b><button type="button" class="student-picker-clear" aria-label="Cambiar estudiante">Cambiar</button>`;
    picked.classList.remove('hidden');
    $('#guardian-student-results').classList.add('hidden'); search.setAttribute('aria-expanded', 'false');
    const phone = student.telefono_apoderado || '';
    if (phone && !$('#guardian-form').elements.telefono.value) $('#guardian-form').elements.telefono.value = phone;
  }
  $('#guardian-student-search').addEventListener('focus', e => renderGuardianStudentResults(e.currentTarget.value));
  $('#guardian-student-search').addEventListener('input', e => {
    $('#guardian-student-select').value = ''; e.currentTarget.dataset.studentSelected = '';
    $('#guardian-student-picked').classList.add('hidden'); renderGuardianStudentResults(e.currentTarget.value);
  });
  $('#guardian-student-search').addEventListener('keydown', e => {
    if (e.key === 'Escape') { $('#guardian-student-results').classList.add('hidden'); e.currentTarget.setAttribute('aria-expanded', 'false'); }
    if (e.key === 'ArrowDown') { e.preventDefault(); $('#guardian-student-results [data-student-id]')?.focus(); }
  });
  $('#guardian-student-results').addEventListener('click', e => {
    const option = e.target.closest('[data-student-id]');
    if (option) pickGuardianStudent((state.communityStudents || []).find(s => s.id === option.dataset.studentId));
  });
  $('#guardian-student-picked').addEventListener('click', e => {
    if (!e.target.closest('.student-picker-clear')) return;
    $('#guardian-student-select').value = ''; $('#guardian-student-search').value = '';
    $('#guardian-student-search').dataset.studentSelected = ''; $('#guardian-student-picked').classList.add('hidden');
    $('#guardian-student-search').focus();
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('#guardian-student-picker')) {
      $('#guardian-student-results').classList.add('hidden'); $('#guardian-student-search').setAttribute('aria-expanded', 'false');
    }
  });
  $('#staff-form').addEventListener('submit', async e => {
    e.preventDefault(); const data = Object.fromEntries(new FormData(e.currentTarget).entries());
    try { await request('staff_create', { method: 'POST', data }); e.currentTarget.reset(); toast('Cuenta de personal creada.'); loadCommunity(); }
    catch (err) { toast(err.message, true); }
  });
  $('#guardian-form').addEventListener('submit', async e => {
    e.preventDefault();
    if (!e.currentTarget.elements.estudiante_id.value) { toast('Busca y selecciona al estudiante antes de continuar.', true); $('#guardian-student-search').focus(); return; }
    const data = Object.fromEntries(new FormData(e.currentTarget).entries()); data.contacto_principal = e.currentTarget.elements.contacto_principal.checked;
    try {
      const result = await request('guardian_create', { method: 'POST', data });
      e.currentTarget.reset(); $('#guardian-student-picked').classList.add('hidden');
      $('#guardian-credential-result').classList.add('hidden');
      if (result.created_account) {
        $('#guardian-temporary-password').textContent = result.temporary_password;
        $('#guardian-credential-result').classList.remove('hidden');
        toast('Cuenta creada. Copia la clave temporal y compártela con el apoderado.');
      } else {
        toast('Estudiante vinculado a la cuenta existente. El apoderado verá a todos sus hijos con su mismo acceso.');
      }
      loadCommunity();
    }
    catch (err) { toast(err.message, true); }
  });
  $('#guardian-form').addEventListener('input', () => $('#guardian-credential-result').classList.add('hidden'));
  $('#copy-guardian-password').addEventListener('click', async () => {
    const password = $('#guardian-temporary-password').textContent;
    try { await navigator.clipboard.writeText(password); toast('Clave temporal copiada.'); }
    catch (_) { toast('No se pudo copiar; selecciona y copia la clave manualmente.', true); }
  });
  $('#copy-guardian-reset-password').addEventListener('click', async () => {
    const password = $('#guardian-reset-password').textContent;
    try { await navigator.clipboard.writeText(password); toast('Clave copiada.'); }
    catch { window.prompt('Copia la clave temporal:', password); }
  });
  let communityImportRows = null;
  $('#community-import-btn').addEventListener('click', () => {
    $('#community-file').value = ''; $('#community-import-status').textContent = ''; $('#community-import-preview').innerHTML = '';
    $('#community-import-confirm').disabled = true; communityImportRows = null; $('#community-import-dialog').showModal();
  });
  $('#community-template').addEventListener('click', () => {
    const csv = '\ufeffcorreo,nombres,apellidos,telefono,parentesco,codigo_estudiante,contacto_principal\r\n';
    const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); link.download = 'plantilla-comunidad-grau-alert.csv'; link.click(); URL.revokeObjectURL(link.href);
  });
  $('#community-file').addEventListener('change', async e => {
    const file = e.target.files[0]; if (!file) return;
    $('#community-import-confirm').disabled = true; $('#community-import-status').textContent = 'Leyendo archivo…';
    try {
      if (!window.XLSX) throw new Error('No se cargó el lector de Excel. Revisa la conexión a internet y vuelve a intentar.');
      const book = XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: false });
      const sheet = book.Sheets[book.SheetNames[0]];
      const matrix = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: '' });
      const aliases = {
        correo: ['correo', 'email', 'correo electronico', 'e-mail'],
        nombres: ['nombres', 'nombre'], apellidos: ['apellidos', 'apellido'],
        telefono: ['telefono', 'celular', 'movil'], parentesco: ['parentesco', 'relacion'],
        codigo_estudiante: ['codigo estudiante', 'codigo del estudiante', 'id estudiante', 'codigo de matricula'],
        contacto_principal: ['contacto principal', 'principal']
      };
      const normalized = value => normHeader(value).replace(/[^a-z0-9]+/g, ' ').trim();
      const headerIndex = matrix.findIndex(row => {
        const cells = row.map(normalized);
        return ['correo', 'nombres', 'apellidos', 'codigo_estudiante'].every(key => aliases[key].some(a => cells.includes(normalized(a))));
      });
      if (headerIndex < 0) throw new Error('Faltan encabezados. Descarga la plantilla y completa correo, nombres, apellidos y codigo_estudiante.');
      const headers = matrix[headerIndex].map(normalized);
      const col = key => headers.findIndex(h => aliases[key].some(a => h === normalized(a)));
      communityImportRows = matrix.slice(headerIndex + 1).map((row, offset) => {
        const get = key => col(key) < 0 ? '' : String(row[col(key)] ?? '').trim();
        const principal = ['si', 'true', '1', 'x', 'principal'].includes(normalized(get('contacto_principal')));
        return { fila: headerIndex + offset + 2, correo: get('correo').toLowerCase(), nombres: get('nombres'), apellidos: get('apellidos'), telefono: get('telefono'), parentesco: get('parentesco'), codigo_estudiante: get('codigo_estudiante'), contacto_principal: principal };
      }).filter(row => [row.correo, row.nombres, row.apellidos, row.telefono, row.parentesco, row.codigo_estudiante].some(value => value !== ''));
      if (!communityImportRows.length) throw new Error('No encontré filas con datos debajo de los encabezados.');
      const previewRows = communityImportRows.slice(0, 8).map(row => `<tr><td>${esc(row.nombres)} ${esc(row.apellidos)}</td><td>${esc(row.correo)}</td><td>${esc(row.codigo_estudiante)}</td><td>${esc(row.parentesco || '—')}</td></tr>`).join('');
      $('#community-import-status').textContent = `Se encontraron ${communityImportRows.length} filas de vínculo. Se crearán las cuentas nuevas y se asociarán los estudiantes por su código.`;
      $('#community-import-preview').innerHTML = `<table><thead><tr><th>APODERADO</th><th>CORREO</th><th>CÓDIGO DEL ESTUDIANTE</th><th>PARENTESCO</th></tr></thead><tbody>${previewRows}</tbody></table>${communityImportRows.length > 8 ? `<p class="form-note">Y ${communityImportRows.length - 8} filas más.</p>` : ''}`;
      $('#community-import-confirm').disabled = false;
    } catch (err) { communityImportRows = null; $('#community-import-status').textContent = err.message; toast(err.message, true); }
  });
  $('#community-import-confirm').addEventListener('click', async () => {
    if (!communityImportRows) return;
    const button = $('#community-import-confirm'); button.disabled = true; button.textContent = 'Importando…';
    try {
      const result = await request('guardian_import', { method: 'POST', data: { rows: communityImportRows } });
      const summary = result.resumen;
      $('#community-import-status').textContent = `Listo: ${summary.cuentas_nuevas} cuentas nuevas, ${summary.vinculos} vínculos creados y ${summary.omitidos} filas omitidas.`;
      if (result.errores.length) $('#community-import-status').textContent += ` Primer error: fila ${result.errores[0].fila}: ${result.errores[0].motivo}`;
      if (result.credenciales.length) {
        const quote = value => `"${String(value).replaceAll('"', '""')}"`;
        const csv = '\ufeffcorreo,clave_temporal\r\n' + result.credenciales.map(row => `${quote(row.correo)},${quote(row.clave_temporal)}`).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = 'credenciales-temporales-apoderados.csv'; link.click(); URL.revokeObjectURL(url);
        $('#community-import-status').textContent += ' Se descargó el archivo con claves temporales; guárdalo de forma segura y compártelo individualmente.';
      }
      toast('Importación de comunidad completada.'); loadCommunity();
      if (!result.credenciales.length) setTimeout(() => $('#community-import-dialog').close(), 2500);
    } catch (err) { toast(err.message, true); }
    finally { button.disabled = false; button.textContent = 'Importar vínculos'; }
  });
  async function loadCalendar() {
    try {
      const result = await request('calendar');
      $('#calendar-rows').innerHTML = result.days.map(d => `<tr><td>${new Date(`${String(d.fecha).slice(0, 10)}T12:00:00`).toLocaleDateString('es-PE', { dateStyle: 'medium' })}</td><td>${esc(d.estado)}</td><td>${esc(d.evento)}</td><td>${d.alarma_activa ? 'Activos' : 'Silenciados'}</td></tr>`).join('');
      $('#calendar-empty').classList.toggle('hidden', result.days.length > 0);
    } catch (e) { toast(e.message, true); }
  }
  $('#calendar-form').addEventListener('submit', async e => {
    e.preventDefault(); const data = Object.fromEntries(new FormData(e.currentTarget).entries()); data.alarma_activa = e.currentTarget.elements.alarma_activa.checked;
    try { await request('calendar_save', { method: 'POST', data }); e.currentTarget.reset(); e.currentTarget.elements.alarma_activa.checked = true; toast('Fecha escolar guardada.'); loadCalendar(); }
    catch (err) { toast(err.message, true); }
  });

  const dateToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  let reportData = null;
  let reportRowsFiltered = [];
  function renderReportRows() {
    if (!reportData) return;
    const level = $('#report-level').value;
    const search = $('#report-search').value.trim().toLocaleLowerCase('es-PE');
    const levelRows = reportData.rows.filter(r => level === 'todos' || (r.nivel || '').toLowerCase() === level);
    const grades = [...new Set(levelRows.map(r => r.grado).filter(Boolean))].sort((a, b) => {
      const order = ['primero', 'segundo', 'tercero', 'cuarto', 'quinto', 'sexto'];
      const label = value => (value.split(/[·-]/).pop() || '').trim().toLowerCase();
      return order.indexOf(label(a)) - order.indexOf(label(b));
    });
    const gradeSelect = $('#report-grade');
    const currentGrade = gradeSelect.value;
    gradeSelect.innerHTML = '<option value="todos">Todos los grados</option>' + grades.map(g => `<option value="${esc(g)}">${esc(g)}</option>`).join('');
    gradeSelect.value = grades.includes(currentGrade) ? currentGrade : 'todos';
    reportRowsFiltered = levelRows.filter(r => (gradeSelect.value === 'todos' || r.grado === gradeSelect.value) && (!search || `${r.nombres} ${r.apellidos} ${r.dni || ''} ${r.codigo}`.toLocaleLowerCase('es-PE').includes(search)));
    if ($('#report-period').value !== 'day') {
      const totals = reportRowsFiltered.reduce((t,r) => ({ present: t.present + r.presentes, late: t.late + r.tardanzas, absent: t.absent + r.faltas, excused: t.excused + r.justificadas }), {present:0,late:0,absent:0,excused:0});
      $('#report-unregistered').previousElementSibling.textContent = 'Justificadas';
      $('#export-report').classList.add('hidden'); $('#close-day').classList.add('hidden');
      $('#report-enrolled').textContent = reportRowsFiltered.length;
      $('#report-present').textContent = totals.present + totals.late;
      $('#report-faltas').textContent = totals.absent;
      $('#report-unregistered').textContent = totals.excused;
      $('#report-rows').innerHTML = reportRowsFiltered.map(r => {
        const details = (r.detalle || []).map(d => {
          const time = d.hora ? ' (' + new Date(d.hora).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' }) + ')' : '';
          return `${d.fecha}: ${d.estado}${time}`;
        }).join('<br>');
        return `<tr><td>${esc(`${r.nombres} ${r.apellidos}`)}<small>DNI ${esc(r.dni || '—')} · Código ${esc(r.codigo)}<br>${details || 'Sin registros en el período'}</small></td><td>${esc(r.grado || '—')}</td><td>${r.presentes}</td><td>${r.tardanzas}</td><td>${r.faltas}</td><td>${r.justificadas}</td></tr>`;
      }).join('');
      $('#report-rows').closest('table').querySelector('thead').innerHTML = '<tr><th>ESTUDIANTE Y DETALLE DIARIO</th><th>GRADO</th><th>PRESENTE</th><th>TARDANZA</th><th>FALTA</th><th>JUSTIFICADA</th></tr>';
      $('#report-empty').classList.toggle('hidden', reportRowsFiltered.length > 0);
      return;
    }
    $('#report-unregistered').previousElementSibling.textContent = 'Sin registro';
    $('#export-report').classList.remove('hidden'); $('#close-day').classList.remove('hidden');
    $('#report-rows').closest('table').querySelector('thead').innerHTML = '<tr><th>ESTUDIANTE</th><th>GRADO</th><th>ESTADO</th><th>HORA</th><th>AVISO WHATSAPP</th></tr>';
    const present = reportRowsFiltered.filter(r => ['presente', 'tardanza'].includes(r.estado)).length;
    const absences = reportRowsFiltered.filter(r => r.estado === 'falta').length;
    const unregistered = reportRowsFiltered.filter(r => !r.estado).length;
    $('#report-enrolled').textContent = reportRowsFiltered.length;
    $('#report-present').textContent = present;
    $('#report-faltas').textContent = absences;
    $('#report-unregistered').textContent = unregistered;
    const names = { presente: 'Presente', tardanza: 'Tardanza', falta: 'Falta', justificada: 'Justificada' };
    const reportDate = $('#report-date').value;
    const formattedDate = new Date(`${reportDate}T12:00:00`).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' });
    $('#report-rows').innerHTML = reportRowsFiltered.map(r => {
      const full = `${r.nombres} ${r.apellidos}`.trim();
      const needsNotice = !r.estado || r.estado === 'falta';
      const phone = whatsappPhone(r.telefono_apoderado);
      const message = `Buen día. Le informamos que ${full} no registra su llegada a la institución el ${formattedDate}. Por favor, comuníquese con la institución. - Grau-Alert.`;
      const markedSent = r.alerta_estado === 'enviada';
      const sendNotice = needsNotice && !markedSent && phone.length >= 11 && phone.length <= 15
        ? `<a class="btn btn-secondary report-wa" href="https://web.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(message)}" target="_blank" rel="noopener">Enviar aviso</a>`
        : needsNotice ? '<span class="report-no-phone">Sin teléfono</span>' : '—';
      const markNotice = needsNotice && !markedSent && phone.length >= 11 && phone.length <= 15 && r.asistencia_id && r.apoderado_id
        ? ` <button type="button" class="btn btn-ghost report-mark-sent" data-report-student="${esc(r.estudiante_id)}" data-report-attendance="${esc(r.asistencia_id)}" data-report-guardian="${esc(r.apoderado_id)}" data-report-message="${esc(message)}">Marcar enviada</button>` : '';
      const noticeCell = markedSent ? '<span class="report-sent">Aviso enviado</span>' : `${sendNotice}${markNotice}`;
      const time = r.estado === 'falta' || !r.hora_ingreso ? '—' : new Date(r.hora_ingreso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' });
      const badgeClass = r.estado === 'falta' ? 'absent-badge' : r.estado === 'tardanza' ? 'late-badge' : '';
      return `<tr><td>${esc(full)}<small>DNI ${esc(r.dni || '—')} · Código ${esc(r.codigo)}</small></td><td>${esc(r.grado || '—')}</td><td><span class="badge ${badgeClass}">${r.estado ? names[r.estado] || esc(r.estado) : 'Sin registro'}</span></td><td>${time}</td><td><div class="report-notice-actions">${noticeCell}</div></td></tr>`;
    }).join('');
    $$('[data-report-student]').forEach(button => button.addEventListener('click', async () => {
      if (!confirm('¿Ya enviaste este mensaje desde WhatsApp?')) return;
      button.disabled = true;
      try {
        await request('report_alert_mark_sent', { method: 'POST', data: { estudiante_id: button.dataset.reportStudent, asistencia_id: button.dataset.reportAttendance, apoderado_id: button.dataset.reportGuardian, mensaje: button.dataset.reportMessage } });
        toast('Aviso marcado como enviado y guardado en Avisos.'); await loadReport(); loadAlerts();
      }
      catch (e) { button.disabled = false; toast(e.message, true); }
    }));
    $('#report-empty').classList.toggle('hidden', reportRowsFiltered.length > 0);
  }
  async function loadReport() {
    if (!$('#report-date').value) $('#report-date').value = dateToday();
    try {
      const period = $('#report-period').value;
      reportData = await request(period === 'day' ? 'report' : 'report_period', { query: `&date=${encodeURIComponent($('#report-date').value)}&period=${encodeURIComponent(period)}` });
      renderReportRows();
    } catch (e) { toast(e.message, true); }
  }
  $('#load-report').addEventListener('click', loadReport);
  $('#report-period').addEventListener('change', loadReport);
  $('#report-level').addEventListener('change', () => { $('#report-grade').value = 'todos'; renderReportRows(); });
  $('#report-grade').addEventListener('change', renderReportRows);
  $('#report-search').addEventListener('input', renderReportRows);
  $('#close-day').addEventListener('click', async () => {
    const fecha = $('#report-date').value;
    if (!fecha || !confirm(`¿Cerrar el día ${fecha}? Se marcará como falta a quien todavía no tenga registro.`)) return;
    try { const result = await request('close_day', { method: 'POST', data: { fecha } }); toast(`Cierre completado: ${result.faltas_marcadas} faltas registradas.`); loadReport(); loadAlerts(); }
    catch (e) { toast(e.message, true); }
  });
  $('#export-report').addEventListener('click', () => {
    if (!reportData) { toast('Primero consulta un reporte.', true); return; }
    const cells = [['DNI', 'Código Estudiante', 'Estudiante', 'Grado', 'Estado', 'Hora'], ...reportRowsFiltered.map(r => [r.dni || '', r.codigo, `${r.nombres} ${r.apellidos}`, r.grado || '', r.estado || 'Sin registro', r.estado === 'falta' ? '' : (r.hora_ingreso || '')])];
    const csv = '\ufeff' + cells.map(row => row.map(v => `"${String(v).replaceAll('"', '""')}"`).join(',')).join('\r\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); a.download = `asistencia-${reportData.summary.fecha}.csv`; a.click(); URL.revokeObjectURL(a.href);
  });
  $('#export-excel').addEventListener('click', () => {
    if (!reportData) { toast('Primero consulta un reporte.', true); return; }
    const period = $('#report-period').value;
    const rows = reportRowsFiltered.map(r => period === 'day'
      ? [r.dni || '', r.codigo, `${r.nombres} ${r.apellidos}`, r.grado || '', r.estado || 'Sin registro', r.hora_ingreso || '']
      : [r.dni || '', r.codigo, `${r.nombres} ${r.apellidos}`, r.grado || '', r.presentes, r.tardanzas, r.faltas, r.justificadas, ...(r.detalle || []).map(d => `${d.fecha}: ${d.estado}`)]);
    const headers = period === 'day' ? ['DNI','Código','Estudiante','Grado','Estado','Hora'] : ['DNI','Código','Estudiante','Grado','Presentes','Tardanzas','Faltas','Justificadas','Detalle diario'];
    const sheet = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book, sheet, 'Asistencia');
    XLSX.writeFile(book, `Grau-Alert-${period}-${$('#report-date').value}.xlsx`);
  });
  $('#export-pdf').addEventListener('click', () => {
    if (!reportData) { toast('Primero consulta un reporte.', true); return; }
    const table = $('#report-rows').closest('table').outerHTML;
    const title = `Asistencia ${$('#report-period').selectedOptions[0].text} · ${$('#report-date').value}`;
    const win = window.open('', '_blank');
    if (!win) { toast('Permite las ventanas emergentes para generar el PDF.', true); return; }
    win.document.write(`<html lang="es"><head><meta charset="utf-8"><title>${esc(title)}</title><style>body{font:12px Arial;padding:24px;color:#17213b}h1{font-size:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccd2df;padding:7px;text-align:left}th{background:#edf1f8}small{display:block;color:#59647b;margin-top:3px}@page{size:landscape;margin:12mm}</style></head><body><h1>${esc(title)}</h1>${table}<script>window.onload=()=>window.print()<\/script></body></html>`); win.document.close();
  });
  async function loadAlerts() {
    try {
      const result = await request('alerts');
      const statusName = { pendiente: 'Pendiente de envío', enviada: 'Marcada como enviada', fallida: 'No enviada', omitida: 'Omitida' };
      $('#alert-rows').innerHTML = result.alerts.map(a => {
        const digits = String(a.apoderado_telefono || '').replace(/\D/g, '');
        const phone = digits.length === 9 ? `51${digits}` : digits.startsWith('0') && digits.length === 10 ? `51${digits.slice(1)}` : digits;
        const sendLink = a.canal === 'whatsapp' && a.estado === 'pendiente' && phone.length >= 11 && phone.length <= 15
          ? `<a class="btn btn-secondary alert-send" href="https://wa.me/${phone}?text=${encodeURIComponent(a.mensaje)}" target="_blank" rel="noopener">Enviar aviso</a>`
          : '';
        const markButton = a.canal === 'whatsapp' && a.estado === 'pendiente' && sendLink
          ? ` <button class="btn btn-ghost alert-send" data-mark-alert="${esc(a.id)}">Marcar enviada</button>` : '';
        const status = a.canal === 'whatsapp' && a.estado === 'pendiente' && !sendLink ? 'Falta teléfono válido' : (statusName[a.estado] || a.estado);
        return `<tr><td>${new Date(a.creado_en).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima' })}</td><td>${esc(`${a.estudiante_nombres} ${a.estudiante_apellidos}`)}</td><td>${esc(`${a.apoderado_nombres || 'Sin vínculo'} ${a.apoderado_apellidos || ''}`)}<small>${esc(a.apoderado_telefono || '')}</small></td><td>${esc(a.mensaje)}</td><td>${esc(a.canal === 'panel' ? 'Portal familiar' : 'WhatsApp')}</td><td>${esc(status)}<div class="alert-actions">${sendLink}${markButton}</div></td></tr>`;
      }).join('');
      $$('[data-mark-alert]').forEach(button => button.addEventListener('click', async () => {
        button.disabled = true;
        try { await request('alert_mark_sent', { method: 'POST', data: { alert_id: button.dataset.markAlert } }); toast('Aviso marcado como enviado.'); loadAlerts(); }
        catch (err) { button.disabled = false; toast(err.message, true); }
      }));
      $('#alerts-empty').classList.toggle('hidden', result.alerts.length > 0);
    } catch (e) { toast(e.message, true); }
  }
  async function loadFamily() {
    try {
      const result = await request('family_home'); const root = $('#family-students');
      if (!result.students.length) { root.innerHTML = '<div class="content-card family-empty">Tu cuenta aún no tiene estudiantes vinculados. Comunícate con la institución.</div>'; return; }
      root.innerHTML = result.students.map(s => `<article class="family-card"><div class="family-head">${s.foto_url ? `<img src="${esc(s.foto_url)}" alt="">` : `<div class="family-avatar">${esc(initials(fullName(s)))}</div>`}<div><h2>${esc(fullName(s))}</h2><p>${esc(s.grado || 'Estudiante')}${s.parentesco ? ` · ${esc(s.parentesco)}` : ''}</p></div></div><h3>ÚLTIMAS ASISTENCIAS</h3>${s.asistencias.length ? s.asistencias.slice(0, 8).map(a => `<div class="family-item"><b>${new Date(`${a.fecha}T12:00:00`).toLocaleDateString('es-PE', { dateStyle: 'full' })} · ${esc(a.estado)}</b><small>${a.hora_ingreso ? new Date(a.hora_ingreso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' }) : 'Sin hora'}</small></div>`).join('') : '<p class="family-empty">Aún no hay asistencias.</p>'}<h3>AVISOS DE LA INSTITUCIÓN</h3>${s.avisos.length ? s.avisos.slice(0, 6).map(a => `<div class="family-item"><b>${esc(a.tipo)}</b><small>${esc(a.mensaje)} · ${new Date(a.creado_en).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima' })}</small></div>`).join('') : '<p class="family-empty">Aún no hay avisos.</p>'}</article>`).join('');
    } catch (e) { toast(e.message, true); }
  }
  function showCard(student) {
    if (!student) return;
    const name = fullName(student);
    $('#card-student-name').textContent = name; $('#id-name').textContent = name; $('#id-grade').textContent = student.grado || 'Estudiante'; $('#id-dni').textContent = `DNI: ${student.dni || '—'}`; $('#id-code').textContent = `Código: ${student.codigo}`;
    const image = $('#id-photo'); image.innerHTML = student.foto_url ? `<img class="id-photo" src="${esc(student.foto_url)}" alt="Foto de ${esc(name)}">` : esc(initials(name));
    $('#qr-code').innerHTML = '';
    if (window.QRCode) {
      state.qrInstance = new QRCode($('#qr-code'), { text: student.qr_token, width: 512, height: 512, colorDark: '#102454', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
      $('#qr-code').dataset.qrToken = student.qr_token;
    } else {
      state.qrInstance = null; $('#qr-code').dataset.qrToken = '';
      toast('No se cargó el generador de QR. Revisa tu conexión e inténtalo otra vez.', true);
    }
    $('#card-dialog').showModal();
  }
  $('#download-qr').addEventListener('click', () => {
    const canvas = $('#qr-code canvas');
    const image = $('#qr-code img');
    let dataUrl = '';
    const source = canvas || (image?.complete ? image : null);
    if (source) {
      const modules = state.qrInstance?._oQRCode?.getModuleCount?.() || 33;
      const sourceSize = source.width || 512;
      const quietZone = Math.ceil(sourceSize / modules * 4);
      const output = document.createElement('canvas'); output.width = sourceSize + quietZone * 2; output.height = sourceSize + quietZone * 2;
      const context = output.getContext('2d'); context.fillStyle = '#fff'; context.fillRect(0, 0, output.width, output.height);
      context.imageSmoothingEnabled = false; context.drawImage(source, quietZone, quietZone, sourceSize, sourceSize);
      dataUrl = output.toDataURL('image/png');
    }
    if (!dataUrl) { toast('No se pudo generar la imagen QR. Vuelve a abrir el carné.', true); return; }
    const code = $('#id-code').textContent.replace(/^Código\s*:?\s*/i, '').replace(/[^a-zA-Z0-9_-]/g, '_') || 'estudiante';
    const link = document.createElement('a'); link.href = dataUrl; link.download = `QR-${code}.png`; document.body.appendChild(link); link.click(); link.remove();
    toast('QR descargado como imagen PNG.');
  });
  async function findToken(token) {
    token = token.trim(); if (!token) return;
    // Si se escriben exactamente 8 dígitos, buscar por DNI como alternativa al carné.
    if (/^\d{8}$/.test(token)) {
      try {
        const result = await request('student_by_dni', { query: `&dni=${encodeURIComponent(token)}` });
        state.currentToken = result.student.qr_token;
        showVerify(result.student);
      } catch (e) { state.currentToken = ''; showVerifyError(e.message); }
      return;
    }
    // El carné Grau-Alert contiene un UUID. Evita enviar al servidor datos
    // de otros QR (por ejemplo, los QR antiguos del Excel) como si fueran UUID.
    const uuid = token.match(/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i);
    if (uuid) token = uuid[0];
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(token)) {
      state.currentToken = ''; showVerifyError('Este código no es un QR de carné Grau-Alert. Abre el carné del estudiante y escanea su QR nuevo.'); return;
    }
    try {
      const result = await request('student_by_qr', { query: `&token=${encodeURIComponent(token)}` });
      state.currentToken = token; showVerify(result.student);
    } catch (e) { state.currentToken = ''; showVerifyError(e.message); }
  }
  function showVerify(student) {
    const already = student.already_marked, absence = already?.estado === 'falta', locked = !!already && !absence;
    const arrivalTime = new Intl.DateTimeFormat('en-GB', { timeZone: 'America/Lima', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date());
    const cutoff = window.SCHOOL_LATE_AFTER || '07:30';
    const willBeLate = arrivalTime > cutoff;
    const description = absence ? 'La falta estaba registrada. Verifica al estudiante para corregirla a presente.' : already ? `Ya se registró ${already.estado === 'tardanza' ? 'una tardanza' : 'la llegada'} hoy a las ${new Date(already.hora_ingreso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' })}.` : `Compara la foto del carné con el estudiante presente. ${willBeLate ? `Después de las ${cutoff}, esta llegada se marcará como tardanza.` : `La hora límite para tardanza es ${cutoff}.`}`;
    const confirmLabel = locked ? 'Asistencia ya registrada' : absence ? '✓ Confirmar llegada' : willBeLate ? '✓ Confirmar tardanza' : '✓ Confirmar identidad y asistencia';
    const phone = whatsappPhone(student.telefono_apoderado);
    const sendNotice = locked && ['presente', 'tardanza'].includes(already?.estado) && phone.length >= 11 && phone.length <= 15
      ? `<a class="btn btn-secondary scanner-whatsapp" href="https://web.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(`Buen día. Le informamos que ${fullName(student)} ${already.estado === 'tardanza' ? 'llegó tarde' : 'ingresó'} a la institución hoy a las ${new Date(already.hora_ingreso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' })}. - Grau-Alert.`)}" target="_blank" rel="noopener">Enviar aviso por WhatsApp</a>`
      : locked && ['presente', 'tardanza'].includes(already?.estado) ? '<p class="form-note">Registra un teléfono válido del apoderado para enviar el aviso.</p>' : '';
    $('#verify-card').innerHTML = `<div class="verify-student">${student.foto_url ? `<img class="verify-photo" src="${esc(student.foto_url)}" alt="Foto de ${esc(fullName(student))}">` : `<div class="verify-photo" style="display:grid;place-items:center;margin:0 auto 11px;font-size:26px;font-weight:800;color:#102454">${esc(initials(fullName(student)))}</div>`}<h2>${esc(fullName(student))}</h2><p class="muted">${esc(student.grado || 'Sin grado')}</p><span class="verify-code">Código ${esc(student.codigo)}</span><div class="verify-check">${description}</div><button id="confirm-attendance" class="btn btn-primary" ${locked ? 'disabled' : ''}>${confirmLabel}</button>${sendNotice}</div>`;
    if (!locked) $('#confirm-attendance').addEventListener('click', confirmAttendance);
  }
  function showVerifyError(message) { $('#verify-card').innerHTML = `<div class="verify-placeholder"><div class="scan-symbol">!</div><b>No se pudo validar</b><p class="muted">${esc(message)}</p></div>`; }
  async function confirmAttendance() {
    const button = $('#confirm-attendance'); if (!button) return;
    button.disabled = true;
    try { await request('attendance_confirm', { method: 'POST', data: { token: state.currentToken } }); toast('Asistencia confirmada.'); const token = state.currentToken; await findToken(token); loadDashboard(); }
    catch (e) { toast(e.message, true); button.disabled = false; }
  }
  $('#manual-find').addEventListener('click', () => findToken($('#manual-qr').value));
  $('#manual-qr').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); findToken(e.currentTarget.value); } });
  async function startScanner() {
    if (state.scanner) return;
    const node = $('#reader'); node.innerHTML = '';
    if (!window.Html5Qrcode) {
      node.innerHTML = '<div class="empty-state">No cargó el lector QR. Comprueba tu conexión a internet y recarga la página. También puedes buscar al estudiante por DNI debajo.</div>';
      return;
    }
    const scanner = new Html5Qrcode('reader'); state.scanner = scanner;
    try {
      const cameras = await Html5Qrcode.getCameras();
      if (!cameras?.length) throw new Error('No se encontró una cámara disponible.');
      const preferred = cameras.find(camera => /back|rear|environment|trasera|posterior/i.test(camera.label)) || cameras[0];
      await scanner.start(preferred.id, {
        fps: 12, qrbox: (width, height) => {
          const size = Math.floor(Math.min(width, height) * 0.78); return { width: size, height: size };
        }, aspectRatio: 1
      }, text => {
        if (state.scanTimer) return;
        state.scanTimer = setTimeout(() => { state.scanTimer = null; }, 2200);
        $('#manual-qr').value = text; findToken(text);
      }, () => { });
    } catch (e) {
      try { if (scanner.isScanning) await scanner.stop(); scanner.clear(); } catch (_) { }
      state.scanner = null;
      const reason = location.protocol !== 'https:' && location.hostname !== 'localhost'
        ? 'La cámara requiere HTTPS (o localhost).'
        : 'No se pudo iniciar la cámara. Permite el acceso desde el candado de la barra del navegador y cierra otras aplicaciones que la estén usando.';
      node.innerHTML = `<div class="empty-state">${esc(reason)} Puedes ingresar el UUID manualmente.</div>`;
      toast(reason, true);
    }
  }
  async function stopScanner() {
    if (!state.scanner) return;
    try { if (state.scanner.isScanning) await state.scanner.stop(); await state.scanner.clear(); } catch (_) { }
    state.scanner = null;
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) stopScanner(); });
  boot();
})();

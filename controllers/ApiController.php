<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../models/StudentModel.php';
require __DIR__ . '/../models/GuardianAlertModel.php';
start_app_session();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : $_POST;
}
function csrf_check(array $data): void
{
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($data['csrf'] ?? ''))) {
        respond(['error' => 'Sesión vencida. Recarga la página e inténtalo de nuevo.'], 419);
    }
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function create_guardian_alerts(PDO $pdo, string $studentId, string $attendanceId, string $kind, string $message): void
{
    GuardianAlertModel::createForStudent($pdo, $studentId, $attendanceId, $kind, $message);
}

try {
    $action = $_GET['action'] ?? '';
    if ($action === 'state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $csrf = csrf_token();
        try {
            $count = (int)db()->query('select count(*) from public.usuarios')->fetchColumn();
            respond(['csrf' => $csrf, 'user' => current_user(), 'needs_setup' => $count === 0, 'setup_key_required' => app_setting('APP_SETUP_KEY') !== '']);
        } catch (Throwable $e) {
            error_log('Grau-Alert database connection: ' . $e->getMessage());
            respond(['csrf' => $csrf, 'error' => 'No se pudo conectar con PostgreSQL. Revisa config.local.php (o las variables DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD) y habilita pdo_pgsql en el PHP que usa Apache.'], 503);
        }
    }

    $data = input();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') csrf_check($data);
    if ($action === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = db();
        if ((int)$pdo->query('select count(*) from public.usuarios')->fetchColumn() !== 0) respond(['error' => 'La configuración inicial ya fue realizada.'], 409);
        $setupKey = app_setting('APP_SETUP_KEY');
        $localRequest = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (($setupKey !== '' && !hash_equals($setupKey, (string)($data['setup_key'] ?? ''))) || ($setupKey === '' && !$localRequest)) respond(['error' => 'Clave de configuración incorrecta.'], 403);
        $email = filter_var(trim((string)($data['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($data['password'] ?? '');
        $names = trim((string)($data['nombres'] ?? ''));
        $last = trim((string)($data['apellidos'] ?? ''));
        if (!$email || $names === '' || $last === '' || strlen($password) < 12) respond(['error' => 'Completa tus datos y usa una contraseña de al menos 12 caracteres.'], 422);
        $stmt = $pdo->prepare("insert into public.usuarios (correo,password_hash,nombres,apellidos,rol) values (:email,:hash,:nombres,:apellidos,'administrador') returning id,correo,nombres,apellidos,rol");
        $stmt->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'nombres' => $names, 'apellidos' => $last]);
        session_regenerate_id(true);
        $_SESSION['user'] = $stmt->fetch();
        $csrf = csrf_token();
        session_write_close();
        respond(['ok' => true, 'user' => $_SESSION['user'], 'csrf' => $csrf]);
    }
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = db()->prepare('select id, correo, password_hash, nombres, apellidos, rol from public.usuarios where lower(correo)=lower(:email) and activo=true');
        $stmt->execute(['email' => trim((string)($data['correo'] ?? ''))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($data['password'] ?? ''), $user['password_hash'])) respond(['error' => 'Correo o contraseña incorrectos.'], 401);
        unset($user['password_hash']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $csrf = $_SESSION['csrf'];
        session_write_close();
        respond(['ok' => true, 'user' => $user, 'csrf' => $csrf]);
    }
    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
    }

    $pdo = db();
    if ($action === 'family_home' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $user = require_user(['apoderado']);
        $stmt = $pdo->prepare("select e.id,e.dni,e.codigo,e.nombres,e.apellidos,e.nombre_completo,e.foto_url,g.nombre as grado,ea.parentesco from public.estudiante_apoderados ea join public.estudiantes e on e.id=ea.estudiante_id left join public.grados g on g.id=e.grado_id where ea.apoderado_id=:uid and e.activo=true order by e.apellidos,e.nombres");
        $stmt->execute(['uid' => $user['id']]);
        $students = $stmt->fetchAll();
        $attendance = $pdo->prepare('select fecha,hora_ingreso,estado from public.asistencias where estudiante_id=:id order by fecha desc limit 30');
        $alerts = $pdo->prepare('select tipo,mensaje,creado_en,estado from public.alertas where estudiante_id=:id and apoderado_id=:uid order by creado_en desc limit 20');
        foreach ($students as &$student) {
            $attendance->execute(['id' => $student['id']]); $student['asistencias'] = $attendance->fetchAll();
            $alerts->execute(['id' => $student['id'], 'uid' => $user['id']]); $student['avisos'] = $alerts->fetchAll();
        }
        unset($student);
        respond(['students' => $students]);
    }

    $user = require_user(['administrador', 'auxiliar', 'tutor']);
    if ($action === 'staff' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_user(['administrador']);
        $rows = $pdo->query("select id,correo,nombres,apellidos,rol,telefono,activo,creado_en from public.usuarios where rol in ('administrador','auxiliar','tutor') order by rol,nombres")->fetchAll();
        respond(['staff' => $rows]);
    }
    if ($action === 'staff_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $email = filter_var(trim((string)($data['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($data['password'] ?? ''); $role = (string)($data['rol'] ?? '');
        $names = trim((string)($data['nombres'] ?? '')); $last = trim((string)($data['apellidos'] ?? ''));
        if (!$email || $names === '' || $last === '' || strlen($password) < 8 || !in_array($role, ['administrador','auxiliar','tutor'], true)) respond(['error' => 'Completa los datos, elige un rol válido y usa una contraseña de al menos 8 caracteres.'], 422);
        try {
            $stmt = $pdo->prepare('insert into public.usuarios (correo,password_hash,nombres,apellidos,telefono,rol) values (:email,:hash,:nombres,:apellidos,:telefono,:rol) returning id,correo,nombres,apellidos,telefono,rol,activo');
            $stmt->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'nombres' => $names, 'apellidos' => $last, 'telefono' => trim((string)($data['telefono'] ?? '')) ?: null, 'rol' => $role]);
        } catch (PDOException $e) { if ($e->getCode() === '23505') respond(['error' => 'Ya existe una cuenta con ese correo.'], 409); throw $e; }
        respond(['ok' => true, 'user' => $stmt->fetch()], 201);
    }
    if ($action === 'guardian_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $email = filter_var(trim((string)($data['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
        $names = trim((string)($data['nombres'] ?? '')); $last = trim((string)($data['apellidos'] ?? ''));
        $studentId = trim((string)($data['estudiante_id'] ?? ''));
        if (!$email || $names === '' || $last === '' || $studentId === '') respond(['error' => 'Completa los datos del apoderado y elige un estudiante.'], 422);
        try {
            $pdo->beginTransaction();
            $existingStmt = $pdo->prepare('select id,correo,nombres,apellidos,telefono,rol,activo from public.usuarios where lower(correo)=lower(:email) limit 1 for update');
            $existingStmt->execute(['email' => $email]);
            $existing = $existingStmt->fetch();
            $createdAccount = false;
            $temporaryPassword = null;
            if ($existing) {
                if ($existing['rol'] !== 'apoderado') { $pdo->rollBack(); respond(['error' => 'Ese correo ya pertenece a una cuenta de personal. Usa otro correo para el apoderado.'], 409); }
                $isActive = $existing['activo'] === true || in_array(strtolower((string)$existing['activo']), ['1','t','true','yes','on'], true);
                if (!$isActive) { $pdo->rollBack(); respond(['error' => 'La cuenta de apoderado de ese correo está desactivada.'], 409); }
                $guardian = $existing;
            } else {
                $temporaryPassword = '12345678';
                $stmt = $pdo->prepare("insert into public.usuarios (correo,password_hash,nombres,apellidos,telefono,rol) values (:email,:hash,:nombres,:apellidos,:telefono,'apoderado') returning id,correo,nombres,apellidos,telefono,rol");
                $stmt->execute(['email' => $email, 'hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT), 'nombres' => $names, 'apellidos' => $last, 'telefono' => trim((string)($data['telefono'] ?? '')) ?: null]);
                $guardian = $stmt->fetch(); $createdAccount = true;
            }
            $link = $pdo->prepare('insert into public.estudiante_apoderados (estudiante_id,apoderado_id,parentesco,contacto_principal) values (:student,:guardian,:relation,:primary)');
            $link->execute(['student' => $studentId, 'guardian' => $guardian['id'], 'relation' => trim((string)($data['parentesco'] ?? '')) ?: null, 'primary' => !empty($data['contacto_principal'])]);
            $pdo->commit();
            respond(['ok' => true, 'guardian' => $guardian, 'created_account' => $createdAccount, 'temporary_password' => $temporaryPassword], 201);
        } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); if ($e->getCode() === '23505') respond(['error' => 'Ya existe una cuenta con ese correo o el apoderado ya está vinculado.'], 409); throw $e; }
    }
    if ($action === 'guardian_import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $rows = $data['rows'] ?? null;
        if (!is_array($rows) || !$rows || count($rows) > 5000) respond(['error' => 'El archivo debe contener entre 1 y 5,000 filas de vínculo.'], 422);
        $pdo->beginTransaction();
        $created = 0; $linked = 0; $omitted = 0; $errors = []; $credentials = []; $newByEmail = [];
        try {
            foreach ($rows as $index => $row) {
                $line = (int)($row['fila'] ?? ($index + 1));
                $email = mb_strtolower(trim((string)($row['correo'] ?? '')));
                $names = preg_replace('/\s+/', ' ', trim((string)($row['nombres'] ?? '')));
                $last = preg_replace('/\s+/', ' ', trim((string)($row['apellidos'] ?? '')));
                $studentCode = trim((string)($row['codigo_estudiante'] ?? ''));
                $phone = trim((string)($row['telefono'] ?? ''));
                $relation = trim((string)($row['parentesco'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $names === '' || $last === '' || $studentCode === '') {
                    $omitted++; $errors[] = ['fila' => $line, 'motivo' => 'Revisa correo, nombres, apellidos y código del estudiante.']; continue;
                }
                if (mb_strlen($names) > 120 || mb_strlen($last) > 120 || mb_strlen($phone) > 40 || mb_strlen($studentCode) > 60 || mb_strlen($relation) > 60) {
                    $omitted++; $errors[] = ['fila' => $line, 'motivo' => 'Un dato supera la longitud permitida.']; continue;
                }
                $studentQuery = $pdo->prepare('select id from public.estudiantes where codigo=:code and activo=true');
                $studentQuery->execute(['code' => $studentCode]); $studentId = $studentQuery->fetchColumn();
                if (!$studentId) { $omitted++; $errors[] = ['fila' => $line, 'motivo' => 'No existe estudiante activo con código ' . $studentCode . '.']; continue; }

                if (!isset($newByEmail[$email])) {
                    $guardianQuery = $pdo->prepare("select id,rol from public.usuarios where lower(correo)=:email limit 1");
                    $guardianQuery->execute(['email' => $email]); $existing = $guardianQuery->fetch();
                    if ($existing && $existing['rol'] !== 'apoderado') {
                        $omitted++; $errors[] = ['fila' => $line, 'motivo' => 'Ese correo ya pertenece a una cuenta de personal.']; continue;
                    }
                    if ($existing) {
                        $guardianId = $existing['id'];
                        $updateGuardian = $pdo->prepare('update public.usuarios set nombres=:names,apellidos=:last,telefono=coalesce(nullif(:phone,\'\'),telefono),activo=true where id=:id');
                        $updateGuardian->execute(['names' => $names, 'last' => $last, 'phone' => $phone, 'id' => $guardianId]);
                    } else {
                        $temporaryPassword = '12345678';
                        $createGuardian = $pdo->prepare("insert into public.usuarios (correo,password_hash,nombres,apellidos,telefono,rol) values (:email,:hash,:names,:last,:phone,'apoderado') returning id");
                        $createGuardian->execute(['email' => $email, 'hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT), 'names' => $names, 'last' => $last, 'phone' => $phone ?: null]);
                        $guardianId = $createGuardian->fetchColumn(); $created++;
                        $credentials[] = ['correo' => $email, 'clave_temporal' => $temporaryPassword];
                    }
                    $newByEmail[$email] = $guardianId;
                }
                $link = $pdo->prepare('insert into public.estudiante_apoderados (estudiante_id,apoderado_id,parentesco,contacto_principal) values (:student,:guardian,:relation,:primary) on conflict (estudiante_id,apoderado_id) do update set parentesco=excluded.parentesco,contacto_principal=excluded.contacto_principal returning (xmax=0) as inserted');
                $link->execute(['student' => $studentId, 'guardian' => $newByEmail[$email], 'relation' => $relation ?: null, 'primary' => filter_var($row['contacto_principal'] ?? false, FILTER_VALIDATE_BOOLEAN)]);
                if ($link->fetchColumn()) $linked++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        respond(['ok' => true, 'resumen' => ['cuentas_nuevas' => $created, 'vinculos' => $linked, 'omitidos' => $omitted], 'errores' => array_slice($errors, 0, 50), 'credenciales' => $credentials]);
    }
    if ($action === 'guardians' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_user(['administrador']);
        $rows = $pdo->query("select u.id,u.correo,u.nombres,u.apellidos,u.telefono,u.activo,e.id as estudiante_id,e.dni,e.codigo,e.nombres as estudiante_nombres,e.apellidos as estudiante_apellidos,g.nombre as grado,ea.parentesco,ea.contacto_principal from public.usuarios u join public.estudiante_apoderados ea on ea.apoderado_id=u.id join public.estudiantes e on e.id=ea.estudiante_id left join public.grados g on g.id=e.grado_id where u.rol='apoderado' and e.activo=true order by u.apellidos,e.apellidos")->fetchAll();
        respond(['guardians' => $rows]);
    }
    if ($action === 'guardian_reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $guardianId = trim((string)($data['guardian_id'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $guardianId)) respond(['error' => 'Apoderado no válido.'], 422);
        $temporaryPassword = '12345678';
        $stmt = $pdo->prepare("update public.usuarios set password_hash=:hash where id=:id and rol='apoderado' and activo=true returning correo");
        $stmt->execute(['hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT), 'id' => $guardianId]);
        $email = $stmt->fetchColumn();
        if (!$email) respond(['error' => 'No encontramos una cuenta activa de apoderado.'], 404);
        respond(['ok' => true, 'correo' => $email, 'temporary_password' => $temporaryPassword]);
    }
    if ($action === 'guardian_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $stmt = $pdo->prepare("select id from public.usuarios where lower(correo)=lower(:email) and rol='apoderado' and activo=true");
        $stmt->execute(['email' => trim((string)($data['correo'] ?? ''))]); $guardianId = $stmt->fetchColumn();
        if (!$guardianId) respond(['error' => 'No encontramos un apoderado activo con ese correo.'], 404);
        try {
            $link = $pdo->prepare('insert into public.estudiante_apoderados (estudiante_id,apoderado_id,parentesco,contacto_principal) values (:student,:guardian,:relation,:primary)');
            $link->execute(['student' => $data['estudiante_id'] ?? '', 'guardian' => $guardianId, 'relation' => trim((string)($data['parentesco'] ?? '')) ?: null, 'primary' => !empty($data['contacto_principal'])]);
        } catch (PDOException $e) { if ($e->getCode() === '23505') respond(['error' => 'Este apoderado ya está vinculado con el estudiante.'], 409); throw $e; }
        respond(['ok' => true]);
    }
    if ($action === 'report' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_user(['administrador','tutor']);
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respond(['error' => 'Fecha no válida.'], 422);
        $stmt = $pdo->prepare("select e.id as estudiante_id,e.dni,e.codigo,e.nombres,e.apellidos,e.nombre_completo,g.nombre as grado,g.nivel,a.id as asistencia_id,a.estado,case when a.estado='falta' then null else a.hora_ingreso end as hora_ingreso,a.metodo,guardian.apoderado_id,guardian.telefono as telefono_apoderado,notice.id as alerta_id,notice.estado as alerta_estado from public.estudiantes e left join public.grados g on g.id=e.grado_id left join public.asistencias a on a.estudiante_id=e.id and a.fecha=:day left join lateral (select u.id as apoderado_id,u.telefono from public.estudiante_apoderados ea join public.usuarios u on u.id=ea.apoderado_id where ea.estudiante_id=e.id and u.rol='apoderado' and u.activo=true order by (ea.contacto_principal and length(regexp_replace(coalesce(u.telefono,''),'[^0-9]','','g'))>=9) desc,(length(regexp_replace(coalesce(u.telefono,''),'[^0-9]','','g'))>=9) desc,ea.contacto_principal desc,u.id limit 1) guardian on true left join lateral (select al.id,al.estado from public.alertas al where al.asistencia_id=a.id and al.apoderado_id=guardian.apoderado_id and al.tipo='inasistencia' and al.canal='whatsapp' order by al.creado_en desc limit 1) notice on true where e.activo=true order by case lower(g.nivel) when 'primaria' then 1 when 'secundaria' then 2 else 3 end,g.nombre,e.apellidos,e.nombres");
        $stmt->execute(['day' => $date]); $rows = $stmt->fetchAll();
        $summary = ['fecha' => $date, 'matriculados' => count($rows), 'presentes' => 0, 'tardanzas' => 0, 'faltas' => 0, 'sin_registro' => 0, 'justificadas' => 0];
        foreach ($rows as $row) {
            $key = match ($row['estado']) { 'presente' => 'presentes', 'tardanza' => 'tardanzas', 'falta' => 'faltas', 'justificada' => 'justificadas', default => 'sin_registro' };
            $summary[$key]++;
        }
        respond(['summary' => $summary, 'rows' => $rows]);
    }
    if ($action === 'report_period' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_user(['administrador','tutor']);
        $anchor = (string)($_GET['date'] ?? date('Y-m-d'));
        $period = (string)($_GET['period'] ?? 'day');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) || !in_array($period, ['day','week','month'], true)) respond(['error' => 'Período de reporte no válido.'], 422);
        $date = new DateTimeImmutable($anchor, new DateTimeZone('America/Lima'));
        $start = $period === 'week' ? $date->modify('monday this week')->format('Y-m-d') : ($period === 'month' ? $date->modify('first day of this month')->format('Y-m-d') : $anchor);
        $end = $period === 'week' ? $date->modify('sunday this week')->format('Y-m-d') : ($period === 'month' ? $date->modify('last day of this month')->format('Y-m-d') : $anchor);
        $stmt = $pdo->prepare("select e.id as estudiante_id,e.dni,e.codigo,e.nombres,e.apellidos,e.nombre_completo,g.nombre as grado,g.nivel,count(a.id) filter(where a.estado in ('presente','tardanza')) as asistencias,count(a.id) filter(where a.estado='presente') as presentes,count(a.id) filter(where a.estado='tardanza') as tardanzas,count(a.id) filter(where a.estado='falta') as faltas,count(a.id) filter(where a.estado='justificada') as justificadas,coalesce(json_agg(json_build_object('fecha',a.fecha,'estado',a.estado,'hora',case when a.estado='falta' then null else a.hora_ingreso end) order by a.fecha) filter(where a.id is not null),'[]'::json) as detalle from public.estudiantes e left join public.grados g on g.id=e.grado_id left join public.asistencias a on a.estudiante_id=e.id and a.fecha between :start and :finish where e.activo=true group by e.id,g.nombre,g.nivel order by case lower(g.nivel) when 'primaria' then 1 when 'secundaria' then 2 else 3 end,g.nombre,e.apellidos,e.nombres");
        $stmt->execute(['start' => $start, 'finish' => $end]); $rows = $stmt->fetchAll();
        $summary = ['inicio' => $start, 'fin' => $end, 'matriculados' => count($rows), 'presentes' => 0, 'tardanzas' => 0, 'faltas' => 0, 'justificadas' => 0];
        foreach ($rows as &$row) { foreach (['asistencias','presentes','tardanzas','faltas','justificadas'] as $field) { $row[$field] = (int)$row[$field]; } if (is_string($row['detalle'])) $row['detalle'] = json_decode($row['detalle'], true) ?: []; foreach (['presentes','tardanzas','faltas','justificadas'] as $field) $summary[$field] += $row[$field]; }
        unset($row); respond(['summary' => $summary, 'rows' => $rows]);
    }
    if ($action === 'calendar' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $pdo->query("select fecha,estado,evento,alarma_activa from public.calendario_escolar where fecha >= date_trunc('year',current_date)::date - interval '1 year' and fecha < date_trunc('year',current_date)::date + interval '3 years' order by fecha")->fetchAll();
        respond(['days' => $rows]);
    }
    if ($action === 'calendar_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $date = trim((string)($data['fecha'] ?? '')); $kind = (string)($data['estado'] ?? ''); $event = trim((string)($data['evento'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($kind, ['lectivo','feriado','vacaciones','suspension'], true) || $event === '') respond(['error' => 'Completa una fecha, tipo de día y motivo.'], 422);
        $stmt = $pdo->prepare('insert into public.calendario_escolar (fecha,estado,evento,alarma_activa,creado_por) values (:date,:state,:event,:alarm,:uid) on conflict (fecha) do update set estado=excluded.estado,evento=excluded.evento,alarma_activa=excluded.alarma_activa,creado_por=excluded.creado_por returning fecha,estado,evento,alarma_activa');
        $stmt->execute(['date' => $date, 'state' => $kind, 'event' => mb_substr($event, 0, 240), 'alarm' => !empty($data['alarma_activa']) ? 'true' : 'false', 'uid' => $user['id']]);
        respond(['ok' => true, 'day' => $stmt->fetch()]);
    }
    if ($action === 'alerts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        require_user(['administrador','tutor']);
        $rows = $pdo->query("select al.id,al.tipo,al.canal,al.estado,al.mensaje,al.creado_en,u.nombres as apoderado_nombres,u.apellidos as apoderado_apellidos,u.telefono as apoderado_telefono,e.nombres as estudiante_nombres,e.apellidos as estudiante_apellidos from public.alertas al join public.estudiantes e on e.id=al.estudiante_id left join public.usuarios u on u.id=al.apoderado_id order by case when al.estado='pendiente' and al.canal='whatsapp' then 0 else 1 end, al.creado_en desc limit 200")->fetchAll();
        respond(['alerts' => $rows]);
    }
    if ($action === 'alert_mark_sent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador','tutor']);
        $alertId = trim((string)($data['alert_id'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $alertId)) respond(['error' => 'Aviso no válido.'], 422);
        $stmt = $pdo->prepare("update public.alertas set estado='enviada',enviada_en=now() where id=:id and canal='whatsapp' and estado='pendiente' returning id");
        $stmt->execute(['id' => $alertId]);
        if (!$stmt->fetchColumn()) respond(['error' => 'El aviso no está pendiente o ya fue marcado como enviado.'], 409);
        respond(['ok' => true]);
    }
    if ($action === 'report_alert_mark_sent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador','tutor']);
        $studentId = trim((string)($data['estudiante_id'] ?? ''));
        $attendanceId = trim((string)($data['asistencia_id'] ?? ''));
        $guardianId = trim((string)($data['apoderado_id'] ?? ''));
        $message = trim((string)($data['mensaje'] ?? ''));
        foreach ([$studentId, $attendanceId, $guardianId] as $uuid) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) respond(['error' => 'No se pudo identificar el aviso.'], 422);
        }
        if ($message === '' || mb_strlen($message) > 1000) respond(['error' => 'El mensaje del aviso no es válido.'], 422);
        $verify = $pdo->prepare("select 1 from public.asistencias a join public.estudiante_apoderados ea on ea.estudiante_id=a.estudiante_id and ea.apoderado_id=:guardian join public.usuarios u on u.id=ea.apoderado_id and u.rol='apoderado' and u.activo=true where a.id=:attendance and a.estudiante_id=:student and a.estado='falta' and length(regexp_replace(coalesce(u.telefono,''),'[^0-9]','','g')) between 9 and 15");
        $verify->execute(['guardian' => $guardianId, 'attendance' => $attendanceId, 'student' => $studentId]);
        if (!$verify->fetchColumn()) respond(['error' => 'No hay una falta y un apoderado válido vinculados para este aviso.'], 404);
        $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare("select id,estado from public.alertas where asistencia_id=:attendance and estudiante_id=:student and apoderado_id=:guardian and tipo='inasistencia' and canal='whatsapp' order by creado_en desc limit 1 for update");
            $existing->execute(['attendance' => $attendanceId, 'student' => $studentId, 'guardian' => $guardianId]);
            $notice = $existing->fetch();
            if ($notice) {
                if ($notice['estado'] !== 'enviada') {
                    $update = $pdo->prepare("update public.alertas set estado='enviada',mensaje=:message,enviada_en=now() where id=:id");
                    $update->execute(['message' => $message, 'id' => $notice['id']]);
                }
            } else {
                $insert = $pdo->prepare("insert into public.alertas (estudiante_id,asistencia_id,apoderado_id,tipo,canal,estado,mensaje,enviada_en) values (:student,:attendance,:guardian,'inasistencia','whatsapp','enviada',:message,now())");
                $insert->execute(['student' => $studentId, 'attendance' => $attendanceId, 'guardian' => $guardianId, 'message' => $message]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        respond(['ok' => true]);
    }
    if ($action === 'close_day' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador','tutor']);
        $day = (string)($data['fecha'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) respond(['error' => 'Fecha no válida.'], 422);
        $calendarCheck = $pdo->prepare('select estado,evento,alarma_activa from public.calendario_escolar where fecha=:date');
        $calendarCheck->execute(['date' => $day]); $calendarDay = $calendarCheck->fetch();
        if ($calendarDay && $calendarDay['estado'] !== 'lectivo') respond(['error' => 'No se puede cerrar asistencia: el calendario marca este día como ' . $calendarDay['estado'] . ' (' . $calendarDay['evento'] . ').'], 409);
        if (!$calendarDay && (int)(new DateTimeImmutable($day))->format('N') > 5) respond(['error' => 'No se puede cerrar asistencia en fin de semana. Registra el día como lectivo en el calendario si hubo clases.'], 409);
        $pdo->beginTransaction();
        $missing = $pdo->prepare("insert into public.asistencias (estudiante_id,fecha,estado,metodo,verificado_por,observacion) select e.id,:day,'falta','manual',:uid,'Cierre diario' from public.estudiantes e where e.activo=true and not exists (select 1 from public.asistencias a where a.estudiante_id=e.id and a.fecha=:day2) on conflict (estudiante_id,fecha) do nothing returning id,estudiante_id");
        $missing->execute(['day' => $day, 'day2' => $day, 'uid' => $user['id']]); $created = $missing->fetchAll();
        foreach ($created as $absence) {
            $details = $pdo->prepare('select nombres,apellidos from public.estudiantes where id=:id'); $details->execute(['id' => $absence['estudiante_id']]); $student = $details->fetch();
            if (!$calendarDay || $calendarDay['alarma_activa']) create_guardian_alerts($pdo, $absence['estudiante_id'], $absence['id'], 'inasistencia', 'No registró su llegada a la institución el ' . $day . '. Por favor, comuníquese con la institución.');
        }
        $pdo->commit(); respond(['ok' => true, 'faltas_marcadas' => count($created)]);
    }
    if ($action === 'students' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        $students = StudentModel::active($pdo, $q);
        if ($user['rol'] !== 'administrador') foreach ($students as &$student) unset($student['telefono_apoderado']);
        unset($student);
        respond(['students' => $students]);
    }
    if ($action === 'grades' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(['grades' => $pdo->query('select id,nombre from public.grados where activo=true order by id')->fetchAll()]);
    }
    if ($action === 'student_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $dni = preg_replace('/\D+/', '', (string)($data['dni'] ?? ''));
        $code = trim((string)($data['codigo'] ?? ''));
        $names = trim((string)($data['nombres'] ?? ''));
        $last = trim((string)($data['apellidos'] ?? ''));
        $grade = $data['grado_id'] ?? null;
        if (!preg_match('/^\d{8}$/', $dni) || $code === '' || $names === '' || $last === '') respond(['error' => 'Ingresa un DNI de 8 dígitos, el código del estudiante, nombres y apellidos.'], 422);
        $stmt = $pdo->prepare('insert into public.estudiantes (dni,codigo,nombres,apellidos,grado_id,foto_url) values (:dni,:codigo,:nombres,:apellidos,:grado,:foto) returning id,dni,codigo,nombres,apellidos,foto_url,qr_token,activo');
        try {
            $stmt->execute(['dni' => $dni, 'codigo' => $code, 'nombres' => $names, 'apellidos' => $last, 'grado' => $grade ?: null, 'foto' => trim((string)($data['foto_url'] ?? '')) ?: null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') respond(['error' => 'Ese DNI o código de estudiante ya está registrado.'], 409);
            throw $e;
        }
        respond(['ok' => true, 'student' => $stmt->fetch()], 201);
    }
    if ($action === 'student_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $id = trim((string)($data['id'] ?? ''));
        $dni = preg_replace('/\D+/', '', (string)($data['dni'] ?? ''));
        $code = trim((string)($data['codigo'] ?? ''));
        $names = trim((string)($data['nombres'] ?? ''));
        $last = trim((string)($data['apellidos'] ?? ''));
        $grade = $data['grado_id'] ?? null;
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || !preg_match('/^\d{8}$/', $dni) || $code === '' || $names === '' || $last === '') respond(['error' => 'Revisa el DNI de 8 dígitos, el código, los nombres y apellidos.'], 422);
        $stmt = $pdo->prepare('update public.estudiantes set dni=:dni,codigo=:codigo,nombres=:nombres,apellidos=:apellidos,nombre_completo=:full,grado_id=:grado,foto_url=:foto,actualizado_en=now() where id=:id and activo=true returning id,dni,codigo,nombres,apellidos,nombre_completo,foto_url,qr_token,activo');
        try {
            $stmt->execute(['dni'=>$dni,'codigo'=>$code,'nombres'=>$names,'apellidos'=>$last,'full'=>trim($names.' '.$last),'grado'=>$grade ?: null,'foto'=>trim((string)($data['foto_url'] ?? '')) ?: null,'id'=>$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') respond(['error' => 'Ese DNI o código de estudiante ya pertenece a otro registro.'], 409);
            throw $e;
        }
        $student = $stmt->fetch();
        if (!$student) respond(['error'=>'No se encontró el estudiante activo.'],404);
        respond(['ok'=>true,'student'=>$student]);
    }
    if ($action === 'student_deactivate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $id = trim((string)($data['id'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) respond(['error'=>'Identificador de estudiante no válido.'],422);
        $stmt = $pdo->prepare('update public.estudiantes set activo=false,actualizado_en=now() where id=:id and activo=true returning id');
        $stmt->execute(['id'=>$id]);
        if (!$stmt->fetch()) respond(['error'=>'No se encontró el estudiante activo.'],404);
        respond(['ok'=>true]);
    }
    if ($action === 'student_import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_user(['administrador']);
        $rows = $data['rows'] ?? null;
        if (!is_array($rows) || !$rows || count($rows) > 1000) respond(['error' => 'El archivo no contiene filas válidas o supera el máximo de 1,000 estudiantes.'], 422);
        $pdo->beginTransaction();
        $counts = ['nuevos' => 0, 'actualizados' => 0, 'omitidos' => 0, 'dias_calendario' => 0];
        $errors = []; $seenDni = []; $seenCode = [];
        $nameKey = static function (string $name): string {
            $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            $parts = preg_split('/[^a-z0-9]+/i', $ascii !== false ? $ascii : $name, -1, PREG_SPLIT_NO_EMPTY);
            sort($parts);
            return implode('|', $parts);
        };
        $legacyIndex = [];
        foreach ($pdo->query("select id,nombre_completo,nombres,apellidos from public.estudiantes where dni is null and codigo like 'EST-%'")->fetchAll() as $legacy) {
            $key = $nameKey((string)($legacy['nombre_completo'] ?: ($legacy['nombres'] . ' ' . $legacy['apellidos'])));
            if ($key !== '') $legacyIndex[$key][] = $legacy['id'];
        }
        try {
            foreach ($rows as $i => $row) {
                $line = (int)($row['fila'] ?? ($i + 1));
                $dni = preg_replace('/\D+/', '', (string)($row['dni'] ?? ''));
                $code = trim((string)($row['codigo'] ?? ''));
                $fullName = preg_replace('/\s+/', ' ', trim((string)($row['nombre_completo'] ?? '')));
                $names = trim((string)($row['nombres'] ?? $fullName));
                $last = trim((string)($row['apellidos'] ?? ''));
                $gradeValue = trim((string)($row['grado'] ?? ''));
                $level = trim((string)($row['nivel'] ?? ''));
                $phone = trim((string)($row['telefono_apoderado'] ?? ''));
                $email = trim((string)($row['correo_estudiante'] ?? ''));
                $loginValue = $row['inicio_sesion_perueduca'] ?? null;
                $externalLogin = $loginValue === null || $loginValue === '' ? null : (filter_var($loginValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
                if (!preg_match('/^\d{8}$/', $dni) || $code === '' || $fullName === '' || $gradeValue === '') {
                    $counts['omitidos']++; $errors[] = ['fila' => $line, 'motivo' => 'Falta DNI válido (8 dígitos), código del estudiante, nombre o grado.']; continue;
                }
                if (($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || mb_strlen($code) > 60 || mb_strlen($fullName) > 180 || mb_strlen($phone) > 40 || mb_strlen($email) > 180) {
                    $counts['omitidos']++; $errors[] = ['fila' => $line, 'motivo' => 'Un dato supera la longitud permitida.']; continue;
                }
                $dniKey = $dni; $codeKey = mb_strtolower($code);
                if (isset($seenDni[$dniKey]) || isset($seenCode[$codeKey])) { $counts['omitidos']++; $errors[] = ['fila' => $line, 'motivo' => 'DNI o código de estudiante duplicado dentro del archivo.']; continue; }
                $seenDni[$dniKey] = true; $seenCode[$codeKey] = true;

                $gradeName = $gradeValue; $schoolLevel = mb_strtolower($level ?: 'secundaria'); $section = null;
                if (preg_match('/^\s*(\d+)\s*[°º.]?\s*(?:secundaria\s*)?([A-Z])\s*$/iu', $gradeValue, $match)) {
                    $section = mb_strtoupper($match[2]);
                    $gradeName = $match[1] . '.° Secundaria ' . $section;
                }
                $gradeStmt = $pdo->prepare('insert into public.grados (nombre,nivel,seccion) values (:name,:level,:section) on conflict (nombre) do update set activo=true returning id');
                $gradeStmt->execute(['name' => $gradeName, 'level' => $schoolLevel, 'section' => $section]);
                $gradeId = $gradeStmt->fetchColumn();
                $byDni = $pdo->prepare('select id,codigo from public.estudiantes where dni=:dni');
                $byDni->execute(['dni' => $dni]); $dniMatch = $byDni->fetch();
                $byCode = $pdo->prepare('select id,dni from public.estudiantes where codigo=:code');
                $byCode->execute(['code' => $code]); $codeMatch = $byCode->fetch();
                if ($dniMatch && $codeMatch && $dniMatch['id'] !== $codeMatch['id']) {
                    $counts['omitidos']++; $errors[] = ['fila' => $line, 'motivo' => 'El DNI y el código ya pertenecen a registros distintos.']; continue;
                }
                $studentId = $dniMatch['id'] ?? $codeMatch['id'] ?? null;
                if (!$studentId) {
                    $candidateIds = $legacyIndex[$nameKey($fullName)] ?? [];
                    if (count($candidateIds) === 1) { $studentId = $candidateIds[0]; unset($legacyIndex[$nameKey($fullName)]); }
                }
                if ($studentId) {
                    if ($codeMatch && $codeMatch['id'] !== $studentId) { $counts['omitidos']++; $errors[] = ['fila' => $line, 'motivo' => 'El código ya está asignado a otro estudiante.']; continue; }
                    $update = $pdo->prepare('update public.estudiantes set dni=:dni,codigo=:code,nombres=:names,apellidos=:last,nombre_completo=:full,grado_id=:grade,telefono_apoderado=:phone,correo=coalesce(nullif(:email,\'\'),correo),inicio_sesion_perueduca=coalesce(cast(:login as boolean),inicio_sesion_perueduca),activo=true where id=:id');
                    $update->execute(['dni' => $dni, 'code' => $code, 'names' => $names, 'last' => $last, 'full' => $fullName, 'grade' => $gradeId, 'phone' => $phone ?: null, 'email' => $email, 'login' => $externalLogin, 'id' => $studentId]);
                    $counts['actualizados']++;
                } else {
                    $insert = $pdo->prepare('insert into public.estudiantes (dni,codigo,nombres,apellidos,nombre_completo,grado_id,telefono_apoderado,correo,inicio_sesion_perueduca) values (:dni,:code,:names,:last,:full,:grade,:phone,:email,cast(:login as boolean))');
                    $insert->execute(['dni' => $dni, 'code' => $code, 'names' => $names, 'last' => $last, 'full' => $fullName, 'grade' => $gradeId, 'phone' => $phone ?: null, 'email' => $email ?: null, 'login' => $externalLogin]);
                    $counts['nuevos']++;
                }
            }
            $calendarRows = $data['calendar_rows'] ?? [];
            if (!is_array($calendarRows) || count($calendarRows) > 500) respond(['error' => 'El calendario supera el máximo de 500 fechas.'], 422);
            foreach ($calendarRows as $i => $row) {
                $date = trim((string)($row['fecha'] ?? ''));
                $kind = (string)($row['estado'] ?? '');
                $event = trim((string)($row['evento'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($kind, ['lectivo','feriado','vacaciones','suspension'], true) || $event === '') {
                    $counts['omitidos']++; $errors[] = ['fila' => (int)($row['fila'] ?? ($i + 1)), 'motivo' => 'Fecha o información del calendario no válida.']; continue;
                }
                $calendar = $pdo->prepare('insert into public.calendario_escolar (fecha,estado,evento,alarma_activa,creado_por) values (:date,:state,:event,:alarm,:uid) on conflict (fecha) do update set estado=excluded.estado,evento=excluded.evento,alarma_activa=excluded.alarma_activa,creado_por=excluded.creado_por');
                $calendar->execute(['date' => $date, 'state' => $kind, 'event' => mb_substr($event, 0, 240), 'alarm' => !empty($row['alarma_activa']) ? 'true' : 'false', 'uid' => $user['id']]);
                $counts['dias_calendario']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        respond(['ok' => true, 'resumen' => $counts, 'errores' => array_slice($errors, 0, 30)]);
    }
    if ($action === 'attendance_today' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("select a.id,a.hora_ingreso,a.estado,a.metodo,e.dni,e.codigo,e.nombres,e.apellidos,e.nombre_completo,e.foto_url,g.nombre as grado from public.asistencias a join public.estudiantes e on e.id=a.estudiante_id left join public.grados g on g.id=e.grado_id where e.activo=true and a.fecha=(now() at time zone 'America/Lima')::date and a.estado in ('presente','tardanza') order by a.hora_ingreso desc limit 300");
        respond(['attendance' => $stmt->fetchAll()]);
    }
    if ($action === 'attendance_manual' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $studentId = trim((string)($data['estudiante_id'] ?? '')); $status = (string)($data['estado'] ?? 'presente');
        if (!in_array($status, ['presente','tardanza','justificada'], true)) respond(['error' => 'Estado de asistencia no válido.'], 422);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("insert into public.asistencias (estudiante_id,estado,metodo,verificado_por,observacion) values (:student,:status,'manual',:uid,:note) on conflict (estudiante_id,fecha) do update set estado=excluded.estado,metodo='manual',hora_ingreso=now(),verificado_por=excluded.verificado_por,observacion=excluded.observacion where public.asistencias.estado='falta' returning id,hora_ingreso,estado");
        $stmt->execute(['student' => $studentId, 'status' => $status, 'uid' => $user['id'], 'note' => trim((string)($data['observacion'] ?? 'Registro manual'))]);
        $attendance = $stmt->fetch();
        if (!$attendance) { $pdo->rollBack(); respond(['error' => 'El estudiante ya tiene un registro hoy.'], 409); }
        $studentStmt = $pdo->prepare('select nombres,apellidos from public.estudiantes where id=:id and activo=true'); $studentStmt->execute(['id' => $studentId]); $student = $studentStmt->fetch();
        if (!$student) { $pdo->rollBack(); respond(['error' => 'Estudiante no encontrado o inactivo.'], 404); }
        $time = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('H:i');
        create_guardian_alerts($pdo, $studentId, $attendance['id'], $status === 'tardanza' ? 'tardanza' : 'ingreso', $status === 'tardanza' ? 'Llegó tarde a la institución a las ' . $time . '.' : 'Ingresó a la institución a las ' . $time . '.');
        $pdo->commit(); respond(['ok' => true, 'attendance' => $attendance]);
    }
    if ($action === 'student_by_qr' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = trim((string)($_GET['token'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token)) respond(['error' => 'Este código no corresponde a un carné QR de Grau-Alert.'], 422);
        $student = StudentModel::byQr($pdo, $token);
        if (!$student) respond(['error' => 'QR no reconocido o estudiante inactivo.'], 404);
        $check = $pdo->prepare("select id,hora_ingreso,estado from public.asistencias where estudiante_id=:id and fecha=(now() at time zone 'America/Lima')::date");
        $check->execute(['id' => $student['id']]);
        $student['already_marked'] = $check->fetch() ?: null;
        respond(['student' => $student]);
    }
    if ($action === 'attendance_confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = trim((string)($data['token'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token)) respond(['error' => 'Este código no corresponde a un carné QR de Grau-Alert.'], 422);
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
        $gradeInfo = $pdo->prepare("select lower(g.nivel) nivel from public.estudiantes e left join public.grados g on g.id=e.grado_id where e.qr_token=:token and e.activo=true");
        $gradeInfo->execute(['token' => $token]); $level = $gradeInfo->fetchColumn();
        if (!$level) respond(['error' => 'QR no reconocido o estudiante inactivo.'], 404);
        $schedule = $level === 'primaria' ? ['start' => '08:00', 'open' => '06:40', 'end' => '13:00', 'grace' => 0] : ['start' => '07:00', 'open' => '06:30', 'end' => '14:30', 'grace' => 5];
        $clock = $now->format('H:i');
        if ($clock < $schedule['open'] || $clock > $schedule['end']) respond(['error' => 'El horario de ingreso para este nivel es de ' . $schedule['open'] . ' a ' . $schedule['end'] . '.'], 409);
        $lateAfter = (new DateTimeImmutable($schedule['start']))->modify('+' . $schedule['grace'] . ' minutes')->format('H:i');
        $status = $clock > $lateAfter ? 'tardanza' : 'presente';
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("insert into public.asistencias (estudiante_id,estado,metodo,verificado_por) select id,:status,'qr',:uid from public.estudiantes where qr_token=:token and activo=true on conflict (estudiante_id,fecha) do update set estado=:status2,metodo='qr',hora_ingreso=now(),verificado_por=excluded.verificado_por,observacion='Ingreso confirmado tras marca de falta' where public.asistencias.estado='falta' returning id,hora_ingreso,estado");
        $stmt->execute(['uid' => $user['id'], 'token' => $token, 'status' => $status, 'status2' => $status]);
        $attendance = $stmt->fetch();
        if (!$attendance) { $pdo->rollBack(); respond(['error' => 'Ya se registró la asistencia de hoy o el QR no es válido.'], 409); }
        $studentStmt = $pdo->prepare('select id,nombres,apellidos from public.estudiantes where qr_token=:token'); $studentStmt->execute(['token' => $token]); $student = $studentStmt->fetch();
        $arrivalMessage = $status === 'tardanza' ? 'Llegó tarde a la institución a las ' . $now->format('H:i') . '.' : 'Ingresó a la institución a las ' . $now->format('H:i') . '.';
        create_guardian_alerts($pdo, $student['id'], $attendance['id'], $status === 'tardanza' ? 'tardanza' : 'ingreso', $arrivalMessage);
        $pdo->commit();
        respond(['ok' => true, 'attendance' => $attendance]);
    }
    respond(['error' => 'Acción no encontrada.'], 404);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    error_log((string)$e);
    $message = http_response_code() === 403 ? $e->getMessage() : 'Error del servidor. Revisa la configuración y los registros.';
    if ($e instanceof PDOException && $e->getCode() === '42703') {
        $message = 'Faltan columnas nuevas en PostgreSQL. Ejecuta database/migrations/2026_11_10_delta_desde_esquema_inicial.sql en tu base y recarga la página.';
    }
    respond(['error' => $message], http_response_code());
}

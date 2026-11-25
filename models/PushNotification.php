<?php
declare(strict_types=1);

final class PushNotification
{
    public static function configured(): bool
    {
        return self::configurationMessage() === '';
    }

    public static function configurationMessage(): string
    {
        $missing = [];
        if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) $missing[] = 'Falta instalar las dependencias PHP con composer install.';
        if (app_setting('PUSH_VAPID_PUBLIC_KEY') === '' || app_setting('PUSH_VAPID_PRIVATE_KEY') === '') $missing[] = 'Faltan PUSH_VAPID_PUBLIC_KEY y PUSH_VAPID_PRIVATE_KEY en las variables de entorno del servidor.';
        return implode(' ', $missing);
    }

    public static function publicKey(): string
    {
        return app_setting('PUSH_VAPID_PUBLIC_KEY');
    }

    public static function sendTestToGuardian(PDO $db, string $guardianId): string
    {
        if (!self::configured()) return 'not_configured';
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            $stmt = $db->prepare('select endpoint,p256dh,auth from public.push_subscriptions where apoderado_id=:guardian');
            $stmt->execute(['guardian' => $guardianId]);
            $rows = $stmt->fetchAll();
            if (!$rows) return 'no_subscription';

            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => app_setting('PUSH_VAPID_SUBJECT', 'mailto:soporte@grau-alert.edu.pe'),
                    'publicKey' => self::publicKey(),
                    'privateKey' => app_setting('PUSH_VAPID_PRIVATE_KEY'),
                ],
            ], ['TTL' => 300, 'urgency' => 'high']);
            $payload = json_encode([
                'title' => 'Grau-Alert: prueba de notificaciones',
                'body' => 'Tu dispositivo está listo para recibir avisos de asistencia.',
                'url' => 'familia.php',
                'tag' => 'grau-alert-prueba-' . bin2hex(random_bytes(4)),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) return 'failed';
            foreach ($rows as $row) {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $row['endpoint'],
                        'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']],
                    ]),
                    $payload
                );
            }
            $sent = false;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) $sent = true;
                else error_log('Grau-Alert push test failed: ' . $report->getReason());
            }
            return $sent ? 'sent' : 'failed';
        } catch (Throwable $e) {
            error_log('Grau-Alert push test: ' . $e->getMessage());
            return 'failed';
        }
    }

    public static function sendForAttendance(PDO $db, string $studentId, string $attendanceId, string $kind, string $message): void
    {
        self::sendBatch($db, [['student_id' => $studentId, 'attendance_id' => $attendanceId, 'kind' => $kind, 'message' => $message]]);
    }

    public static function sendBatch(PDO $db, array $items): void
    {
        if (!self::configured()) return;
        $byStudent = [];
        foreach ($items as $item) {
            $id = (string)($item['student_id'] ?? '');
            if ($id !== '') $byStudent[$id] = $item;
        }
        if (!$byStudent) return;
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            $auth = [
                'VAPID' => [
                    'subject' => app_setting('PUSH_VAPID_SUBJECT', 'mailto:soporte@grau-alert.edu.pe'),
                    'publicKey' => self::publicKey(),
                    'privateKey' => app_setting('PUSH_VAPID_PRIVATE_KEY'),
                ],
            ];
            $webPush = new \Minishlink\WebPush\WebPush($auth, ['TTL' => 86400, 'urgency' => 'high']);
            $params = [];
            $holders = [];
            foreach (array_keys($byStudent) as $index => $id) { $key = ':student' . $index; $holders[] = $key; $params[$key] = $id; }
            $stmt = $db->prepare("select distinct e.id as student_id,ps.endpoint,ps.p256dh,ps.auth,e.nombres,e.apellidos from public.estudiante_apoderados ea join public.push_subscriptions ps on ps.apoderado_id=ea.apoderado_id join public.estudiantes e on e.id=ea.estudiante_id where ea.estudiante_id in (" . implode(',', $holders) . ')');
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll();
            if (!$subscriptions) return;
            foreach ($subscriptions as $subscriptionRow) {
                $item = $byStudent[$subscriptionRow['student_id']] ?? null;
                if (!$item) continue;
                $kind = (string)($item['kind'] ?? 'ingreso');
                $attendanceId = (string)($item['attendance_id'] ?? '');
                $payload = json_encode([
                    'title' => $kind === 'inasistencia' ? 'Aviso de inasistencia' : ($kind === 'tardanza' ? 'Aviso de tardanza' : 'Aviso de ingreso'),
                    'body' => trim($subscriptionRow['nombres'] . ' ' . $subscriptionRow['apellidos']) . ': ' . (string)($item['message'] ?? ''),
                    'url' => 'familia.php',
                    'tag' => 'asistencia-' . $attendanceId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payload === false) continue;
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $subscriptionRow['endpoint'],
                    'keys' => ['p256dh' => $subscriptionRow['p256dh'], 'auth' => $subscriptionRow['auth']],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }
            $expired = [];
            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess() && in_array((int)$report->getResponse()?->getStatusCode(), [404, 410], true)) {
                    $expired[] = $report->getEndpoint();
                } elseif (!$report->isSuccess()) {
                    error_log('Grau-Alert push delivery failed: ' . $report->getReason());
                }
            }
            if ($expired) {
                $delete = $db->prepare('delete from public.push_subscriptions where endpoint=:endpoint');
                foreach (array_unique($expired) as $endpoint) $delete->execute(['endpoint' => $endpoint]);
            }
        } catch (Throwable $e) {
            error_log('Grau-Alert push notification: ' . $e->getMessage());
        }
    }
}

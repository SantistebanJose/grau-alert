<?php
declare(strict_types=1);

final class GuardianAlertModel
{
    public static function createForStudent(PDO $db, string $studentId, string $attendanceId, string $kind, string $message): void
    {
        $stmt = $db->prepare("insert into public.alertas (estudiante_id,asistencia_id,apoderado_id,tipo,canal,estado,mensaje) select :student,:attendance,u.id,:kind,case when coalesce(nullif(regexp_replace(coalesce(u.telefono,e.telefono_apoderado),'[^0-9]','','g'),''),'')<>'' then 'whatsapp' else 'panel' end,case when coalesce(nullif(regexp_replace(coalesce(u.telefono,e.telefono_apoderado),'[^0-9]','','g'),''),'')<>'' then 'pendiente' else 'enviada' end,:message from public.estudiante_apoderados ea join public.usuarios u on u.id=ea.apoderado_id and u.activo=true join public.estudiantes e on e.id=ea.estudiante_id where ea.estudiante_id=:student2 and u.rol='apoderado'");
        $stmt->execute(['student' => $studentId, 'attendance' => $attendanceId, 'kind' => $kind, 'message' => $message, 'student2' => $studentId]);
    }
}

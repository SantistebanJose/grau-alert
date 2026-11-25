<?php
declare(strict_types=1);

final class StudentModel
{
    public static function active(PDO $db, string $query = ''): array
    {
        $stmt = $db->prepare("select e.id,e.dni,e.codigo,e.nombres,e.apellidos,e.nombre_completo,e.telefono_apoderado,e.foto_url,e.qr_token,e.activo,e.grado_id,g.nombre as grado,g.nivel from public.estudiantes e left join public.grados g on g.id=e.grado_id where e.activo=true and (:q='' or e.dni ilike :like1 or e.codigo ilike :like2 or e.nombres ilike :like3 or e.apellidos ilike :like4 or e.nombre_completo ilike :like5) order by case lower(g.nivel) when 'primaria' then 1 when 'secundaria' then 2 else 3 end, g.nombre, e.apellidos,e.nombres limit 1000");
        $like = '%' . $query . '%';
        $stmt->execute(['q' => $query, 'like1' => $like, 'like2' => $like, 'like3' => $like, 'like4' => $like, 'like5' => $like]);
        return $stmt->fetchAll();
    }

    public static function byQr(PDO $db, string $token): ?array
    {
        $stmt = $db->prepare("select e.id,e.dni,e.codigo,e.nombres,e.apellidos,e.nombre_completo,e.foto_url,g.nombre as grado,g.nivel,guardian.telefono as telefono_apoderado from public.estudiantes e left join public.grados g on g.id=e.grado_id left join lateral (select u.telefono from public.estudiante_apoderados link join public.usuarios u on u.id=link.apoderado_id where link.estudiante_id=e.id and u.rol='apoderado' and u.activo=true order by (link.contacto_principal and length(regexp_replace(coalesce(u.telefono,''),'[^0-9]','','g'))>=9) desc,(length(regexp_replace(coalesce(u.telefono,''),'[^0-9]','','g'))>=9) desc,link.contacto_principal desc,u.id limit 1) guardian on true where e.qr_token=:token and e.activo=true");
        $stmt->execute(['token' => $token]);
        $student = $stmt->fetch();
        return $student ?: null;
    }
}

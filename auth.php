<?php
declare(strict_types=1);

class AvtoparkiAuth {
    const MAX_ATTEMPT = 5;
    const LOCK_SECONDS = 900;
    const PERM_TRIP_CREATE = 'trip_create';

    protected static function accountById(int $id): ?array {
        $s = avtoparki_db()->prepare('SELECT * FROM account WHERE id_pk = :id');
        $s->execute([':id' => $id]);
        $r = $s->fetch();
        return $r ?: null;
    }
    public static function account(): ?array {
        return !empty($_SESSION['avtoparki_user_id']) ? self::accountById((int)$_SESSION['avtoparki_user_id']) : null;
    }
    public static function current_username(): string { $a = self::account(); return $a ? $a['username'] : ''; }
    public static function current_user_id(): int { return (int)($_SESSION['avtoparki_user_id'] ?? 0); }
    public static function is_admin(): bool { $a = self::account(); return $a && (int)$a['is_admin'] === 1; }
    public static function must_change_password(): bool { $a = self::account(); return $a ? ((int)$a['must_change'] === 1) : false; }
    public static function is_authenticated(): bool { $a = self::account(); return $a && (int)$a['is_active'] === 1; }
    protected static function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'); }
    protected static function attempt_row(string $ip): array {
        $s = avtoparki_db()->prepare('SELECT * FROM login_attempt WHERE ip = :ip');
        $s->execute([':ip' => $ip]);
        return $s->fetch() ?: ['ip' => $ip, 'fails' => 0, 'locked_until' => null];
    }
    public static function login(string $username, string $password): void {
        $pdo = avtoparki_db(); $ip = self::client_ip(); $row = self::attempt_row($ip);
        if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
            throw new AvtoparkiError('Too many sign-in attempts. Please try again in 15 minutes.', 429);
        }
        $s = $pdo->prepare('SELECT * FROM account WHERE username = :u COLLATE NOCASE LIMIT 1');
        $s->execute([':u' => trim($username)]); $a = $s->fetch();
        $ok = $a && (int)$a['is_active'] === 1 && password_verify($password, $a['password_hash']);
        if (!$ok) {
            $f = (int)$row['fails'] + 1; $l = $f >= self::MAX_ATTEMPT ? date('Y-m-d H:i:s', time() + self::LOCK_SECONDS) : null;
            $pdo->prepare('INSERT INTO login_attempt(ip,fails,locked_until) VALUES(:ip,:f,:l) ON CONFLICT(ip) DO UPDATE SET fails=:f,locked_until=:l')
                ->execute([':ip'=>$ip, ':f'=>$f, ':l'=>$l]);
            throw new AvtoparkiError('Invalid username or password.', 401);
        }
        $pdo->prepare('DELETE FROM login_attempt WHERE ip=:ip')->execute([':ip'=>$ip]);
        session_regenerate_id(true); $_SESSION['avtoparki_user_id'] = (int)$a['id_pk']; $_SESSION['avtoparki_last_activity'] = time();
    }
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
        session_destroy();
    }
    public static function change_password(string $current,string $new,string $repeat,string $newUsername=''): void {
        $a=self::account();
        if(!$a || !password_verify($current,$a['password_hash'])) throw new AvtoparkiError('Current password is invalid.',403);
        if($new!==$repeat) throw new AvtoparkiError('New passwords do not match.');
        $passwordErrors = [];
        if(strlen($new)<8) $passwordErrors[] = 'at least 8 characters';
        if(!preg_match('/[A-Z]/', $new)) $passwordErrors[] = 'at least 1 uppercase Latin letter';
        if(!preg_match('/[0-9]/', $new)) $passwordErrors[] = 'at least 1 number';
        if(!preg_match('/[^a-zA-Z0-9]/', $new)) $passwordErrors[] = 'at least 1 special character';
        if($passwordErrors) throw new AvtoparkiError('Password must contain: ' . implode(', ', $passwordErrors) . '.');
        if(password_verify($new,$a['password_hash'])) throw new AvtoparkiError('The new password must differ from the current password.');
        $pdo = avtoparki_db();
        // The primary admin can change their username during initial setup.
        if ((int)$a['is_admin'] === 1 && (int)$a['must_change'] === 1) {
            $newUsername = trim($newUsername);
            if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$newUsername)) throw new AvtoparkiError('Admin username must be 3-40 characters and contain only Latin letters, numbers, or . _ - characters.');
            $dupe=$pdo->prepare('SELECT id_pk FROM account WHERE username=:u COLLATE NOCASE AND id_pk!=:id');
            $dupe->execute([':u'=>$newUsername,':id'=>$a['id_pk']]);
            if($dupe->fetchColumn()) throw new AvtoparkiError('This username already exists.',409);
            $pdo->prepare('UPDATE account SET username=:u,password_hash=:h,must_change=0 WHERE id_pk=:id')->execute([':u'=>$newUsername,':h'=>password_hash($new,PASSWORD_DEFAULT),':id'=>$a['id_pk']]);
            return;
        }
        $pdo->prepare('UPDATE account SET password_hash=:h,must_change=0 WHERE id_pk=:id')->execute([':h'=>password_hash($new,PASSWORD_DEFAULT),':id'=>$a['id_pk']]);
    }
    public static function require_auth(): void {
        if(!self::is_authenticated()){ http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Please sign in to perform this action.'],JSON_UNESCAPED_UNICODE); exit; }
    }
    public static function require_admin(): void { if(!self::is_admin()) throw new AvtoparkiError('Only an administrator can perform this action.',403); }

    public static function has_permission(string $permission): bool {
        if (self::is_admin()) return true;
        $uid = self::current_user_id(); if (!$uid) return false;
        $s = avtoparki_db()->prepare('SELECT allowed FROM user_permission WHERE user_id=:uid AND permission=:p');
        $s->execute([':uid'=>$uid, ':p'=>$permission]);
        return (int)($s->fetchColumn() ?: 0) === 1;
    }
    public static function require_permission(string $permission, string $message): void {
        if (!self::has_permission($permission)) throw new AvtoparkiError($message, 403);
    }
    public static function permissions(): array {
        return ['trip_create' => self::has_permission(self::PERM_TRIP_CREATE)];
    }
    public static function users(): array {
        self::require_admin();
        $rows = avtoparki_db()->query('SELECT id_pk,username,is_admin,is_active,created_at,updated_at FROM account ORDER BY is_admin DESC,updated_at DESC,id_pk DESC')->fetchAll();
        $p = avtoparki_db()->prepare('SELECT allowed FROM user_permission WHERE user_id=:uid AND permission=:p');
        foreach ($rows as &$u) {
            $u['can_add_trip'] = (int)$u['is_admin'] === 1 ? 1 : 0;
            if ((int)$u['is_admin'] !== 1) { $p->execute([':uid'=>$u['id_pk'], ':p'=>self::PERM_TRIP_CREATE]); $u['can_add_trip'] = (int)($p->fetchColumn() ?: 0); }
        }
        return $rows;
    }
    public static function create_user(string $u,string $p,bool $canAddTrip=false): void {
        self::require_admin(); $u=trim($u);
        if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$u)) throw new AvtoparkiError('Username must be 3-40 characters and contain only Latin letters, numbers, or . _ - characters.');
        if($p === '') throw new AvtoparkiError('Temporary password cannot be empty.');
        $pdo=avtoparki_db();
        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM account WHERE is_admin = 0')->fetchColumn();
        if($userCount >= 5) throw new AvtoparkiError('You can create a maximum of 5 users.', 409);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO account(username,password_hash,must_change,is_admin,is_active) VALUES(:u,:h,1,0,1)')->execute([':u'=>$u,':h'=>password_hash($p,PASSWORD_DEFAULT)]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_permission(user_id,permission,allowed) VALUES(:uid,:p,:a)')->execute([':uid'=>$id,':p'=>self::PERM_TRIP_CREATE,':a'=>$canAddTrip?1:0]);
            $pdo->commit();
        } catch(PDOException $e) { if($pdo->inTransaction())$pdo->rollBack(); throw new AvtoparkiError('This username already exists.',409); }
    }

    public static function reset_user_password(int $id, string $password): void {
        self::require_admin();
        $a=self::accountById($id);
        if(!$a || (int)$a['is_admin']===1) throw new AvtoparkiError('User not found.',404);
        if($password === '') throw new AvtoparkiError('Temporary password cannot be empty.');
        avtoparki_db()->prepare('UPDATE account SET password_hash=:h,must_change=1 WHERE id_pk=:id')->execute([':h'=>password_hash($password,PASSWORD_DEFAULT),':id'=>$id]);
    }

    public static function toggle_user(int $id,bool $active): void {
        self::require_admin(); $a=self::accountById($id);
        if(!$a) throw new AvtoparkiError('User not found.',404);
        if((int)$a['is_admin']===1) throw new AvtoparkiError('The primary administrator cannot be deactivated.');
        avtoparki_db()->prepare('UPDATE account SET is_active=:a WHERE id_pk=:id')->execute([':a'=>$active?1:0,':id'=>$id]);
    }

    public static function delete_user(int $id): void {
        self::require_admin(); $a=self::accountById($id);
        if(!$a) throw new AvtoparkiError('User not found.',404);
        if((int)$a['is_admin']===1) throw new AvtoparkiError('The primary administrator cannot be deleted.');
        avtoparki_db()->prepare('DELETE FROM account WHERE id_pk=:id')->execute([':id'=>$id]);
    }
    public static function set_trip_create_permission(int $id,bool $allowed): void {
        self::require_admin(); $a=self::accountById($id);
        if(!$a) throw new AvtoparkiError('User not found.',404);
        if((int)$a['is_admin']===1) return;
        $pdo=avtoparki_db();
        $pdo->prepare('INSERT INTO user_permission(user_id,permission,allowed) VALUES(:uid,:p,:a) ON CONFLICT(user_id,permission) DO UPDATE SET allowed=:a')
            ->execute([':uid'=>$id,':p'=>self::PERM_TRIP_CREATE,':a'=>$allowed?1:0]);
        $pdo->prepare("UPDATE access_request SET status=:s,decided_at=CURRENT_TIMESTAMP WHERE user_id=:uid AND permission=:p")
            ->execute([':s'=>$allowed?'approved':'rejected',':uid'=>$id,':p'=>self::PERM_TRIP_CREATE]);
    }
    public static function trip_access_request_status(): string {
        if (self::is_admin() || self::has_permission(self::PERM_TRIP_CREATE)) return 'approved';
        $s=avtoparki_db()->prepare('SELECT status FROM access_request WHERE user_id=:uid AND permission=:p');
        $s->execute([':uid'=>self::current_user_id(), ':p'=>self::PERM_TRIP_CREATE]);
        return (string)($s->fetchColumn() ?: 'none');
    }
    public static function request_trip_access(): void {
        if (self::is_admin() || self::has_permission(self::PERM_TRIP_CREATE)) throw new AvtoparkiError('You already have permission to add trips.');
        $pdo=avtoparki_db();
        $pdo->prepare("INSERT INTO access_request(user_id,permission,status,requested_at,decided_at) VALUES(:uid,:p,'pending',CURRENT_TIMESTAMP,NULL) ON CONFLICT(user_id,permission) DO UPDATE SET status='pending',requested_at=CURRENT_TIMESTAMP,decided_at=NULL")
            ->execute([':uid'=>self::current_user_id(), ':p'=>self::PERM_TRIP_CREATE]);
    }
    public static function access_requests(): array {
        self::require_admin();
        return avtoparki_db()->query("SELECT r.id_pk,r.user_id,r.permission,r.status,r.requested_at,a.username FROM access_request r JOIN account a ON a.id_pk=r.user_id WHERE r.permission='trip_create' AND r.status='pending' ORDER BY r.requested_at DESC, r.id_pk DESC")->fetchAll();
    }
    public static function decide_trip_access_request(int $requestId,bool $approve): void {
        self::require_admin(); $pdo=avtoparki_db(); $pdo->beginTransaction();
        try {
            $s=$pdo->prepare("SELECT * FROM access_request WHERE id_pk=:id AND permission=:p AND status='pending'");
            $s->execute([':id'=>$requestId,':p'=>self::PERM_TRIP_CREATE]); $r=$s->fetch();
            if(!$r) throw new AvtoparkiError('Access request not found.',404);
            $pdo->prepare("UPDATE access_request SET status=:s,decided_at=CURRENT_TIMESTAMP WHERE id_pk=:id")->execute([':s'=>$approve?'approved':'rejected',':id'=>$requestId]);
            if($approve){
                $pdo->prepare('INSERT INTO user_permission(user_id,permission,allowed) VALUES(:uid,:p,1) ON CONFLICT(user_id,permission) DO UPDATE SET allowed=1')->execute([':uid'=>$r['user_id'],':p'=>self::PERM_TRIP_CREATE]);
            }
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }

}

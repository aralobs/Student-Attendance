if (!$user) {
    setFlash('danger', 'User not found.');
    header('Location: users.php');
    exit;
}

// NEW: block editing archived users
if ($user['is_active'] == 2) {
    setFlash('warning', 'Archived users cannot be edited. Restore them first.');
    header('Location: users.php');
    exit;
}

$errors = [];
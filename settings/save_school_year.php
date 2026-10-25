<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/school_years.php';
requireAdmin(); requireCsrfPost();
try {
    $input = ['label'=>$_POST['label'] ?? null,'starts_on'=>$_POST['starts_on'] ?? null,'ends_on'=>$_POST['ends_on'] ?? null];
    if (isset($_POST['id'])) { $input['id'] = $_POST['id']; $input['expected_version'] = $_POST['expected_version'] ?? null; }
    savePlannedSchoolYear(getDB(),currentUser(),$input);
    setFlash('success','Planned school year saved.');
} catch (DomainException|InvalidArgumentException $error) { setFlash('danger',$error->getMessage()); }
catch (Throwable $error) { setFlash('danger','Unable to save the school year. Please try again.'); }
header('Location: school_years.php'); exit;

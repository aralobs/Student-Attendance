<?php
require_once __DIR__.'/../includes/student_photos.php';
$checks=0;
function photoCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
foreach (['png','jpeg','gif','webp'] as $format) {
    $image=imagecreatetruecolor(3,2);imagefill($image,0,0,imagecolorallocate($image,42,87,142));
    ob_start();('image'.$format)($image);$bytes=ob_get_clean();imagedestroy($image);
    $clean=sanitizeStudentPhoto($bytes.'<?php SECRET_PAYLOAD(); ?>');
    photoCheck(!str_contains($clean['bytes'],'SECRET_PAYLOAD'),'Embedded code survived re-encoding');
    $info=getimagesizefromstring($clean['bytes']);
    photoCheck($info[0]===3&&$info[1]===2,'Image dimensions changed');
    photoCheck($clean['extension']===($format==='jpeg'?'jpg':'png'),'Stored extension does not match encoding');
    photoCheck($info['mime']===($format==='jpeg'?'image/jpeg':'image/png'),'Unsafe stored MIME');
}
foreach(['','<?php echo 1;?>','<svg xmlns="http://www.w3.org/2000/svg"/>',str_repeat('a',2*1024*1024+1),"\x89PNG\r\n\x1a\ntruncated"] as $bytes) {
    try{sanitizeStudentPhoto($bytes);throw new LogicException('Unsafe image accepted');}catch(InvalidArgumentException $expected){$checks++;}
}
$image=imagecreatetruecolor(1,1);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$large=substr_replace($bytes,pack('N',5000),16,4);
try{sanitizeStudentPhoto($large);throw new LogicException('Excessive dimensions accepted');}catch(InvalidArgumentException $expected){$checks++;}
try{storeStudentPhotoUpload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>__FILE__,'name'=>'fake.png']);throw new LogicException('Non-uploaded server file accepted');}catch(InvalidArgumentException $expected){$checks++;}
echo "Photo upload security checks passed ($checks checks; synthetic images only).\n";

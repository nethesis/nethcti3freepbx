<?php
out(_('Creating the database table'));
//Database
$table = 'nethcti3';
$dbh = \FreePBX::Database();
try {
    $sql = "CREATE TABLE IF NOT EXISTS $table(
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `subject` VARCHAR(60),
    `body` TEXT);";
    $sth = $dbh->prepare($sql);
    $result = $sth->execute();
} catch (PDOException $e) {
    $result = $e->getMessage();
}
if ($result === true) {
    out(_('Table Created'));
} else {
    out(_('Something went wrong'));
    out($result);
}

// Register FeatureCode
// $fcc = new featurecode('nethcti3', 'nethcti3');
// $fcc->setDescription('Nethcti3 welcome message');
// $fcc->setDefault('*43556');
// $fcc->update();
// unset($fcc);
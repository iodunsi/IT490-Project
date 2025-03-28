<?php
$inputPassword = "TestPass123@"; // Replace with the password printed in the PHP script
$storedHash = "4b3abf682ad074e0123febc2219789e0dce41d051e4865859101d423ebc788b3"; // Replace with the hash from MySQL

if (password_verify($inputPassword, $storedHash)) {
    echo "✅ Password matches! Login should work.\n";
} else {
    echo "❌ Incorrect password.\n";
}

$password = "test";
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
echo "Hashed Password: " . $hashedPassword . "\n";

?>

<?php
$hash = '$2y$10$vD7b1b3mREb62CgR1qFm/.7L6L8hC9iW4qM3aU7Iym7C1Y7vWshG6';
$password = 'password123';
if (password_verify($password, $hash)) {
    echo "Password verification succeeded!";
} else {
    echo "Password verification failed!";
}
?>

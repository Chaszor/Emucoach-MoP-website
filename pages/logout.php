<?php
session_start();
session_destroy();
header("Location: /wowsite/index.php");
exit;

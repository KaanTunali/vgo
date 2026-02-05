<!DOCTYPE html>
<html>
<head>
    <title>VGO - Giriş Yap</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-body">
                        <h3 class="text-center">VGO Giriş</h3>
                        <form action="login.kontrol.php" method="POST">
                            <div class="mb-3">
                                <label>E-posta</label>
                                <input type="email" name="email" class="form-control" required placeholder="ornek@mail.com">
                            </div>
                            <div class="mb-3">
                                <label>Şifre</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" name="remember" class="form-check-input" id="remember">
                                <label class="form-check-label" for="remember">Beni hatırla</label>
                                <a href="#" class="float-end">Şifremi unuttum?</a>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Giriş Yap</button>
                            <div class="text-center mt-3">
                                <small>Hesabın yok mu? <a href="register.php">Kayıt ol</a></small>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
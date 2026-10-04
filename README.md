# PHP Görev Hatırlatıcısı

MySQL'de kalıcı olarak saklanan görevler için PHP yönetim paneli ve SMTP e-posta hatırlatıcısı.

## Gereksinimler

- PHP 8.1 veya üzeri; `pdo_mysql`, `openssl` ve `mbstring` eklentileri
- MySQL 5.7+ veya MariaDB 10.2+
- Hatırlatmalar için sunucuda cron desteği ve SMTP hesabı

## Kurulum

1. Bir MySQL veritabanı ve kullanıcı oluşturun, `schema.sql` dosyasını veritabanına **bir kez** aktarın.
2. Web sunucusunda aşağıdaki ortam değişkenlerini tanımlayın:

   | Değişken | Açıklama |
   | --- | --- |
   | `DB_HOST`, `DB_PORT` | MySQL sunucusu ve portu (varsayılan `127.0.0.1:3306`) |
   | `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Veritabanı adı ve erişim bilgileri |
   | `ADMIN_USER`, `ADMIN_PASSWORD` | Yönetim paneli giriş bilgileri; parola zorunludur |
   | `APP_URL` | Uygulamanın tam adresi; ör. `https://hatirlatici.example.com` |
   | `APP_TIMEZONE` | Varsayılan saat dilimi (varsayılan `Europe/Istanbul`) |

   Bu değerleri kaynak koda yazmak yerine web sunucusu/PHP ortamında tutun. Yönetici parolası tanımlanmamışsa giriş yapılamaz.

3. Siteyi HTTPS üzerinden yayınlayın ve `index.php` sayfasını açın. Yönetici olarak giriş yaptıktan sonra SMTP sunucu, port, TLS/SSL, kullanıcı adı/parola, gönderen bilgileri ve saat dilimini ayarlayın.
4. Sunucunun cron alanına hatırlatma betiğini dakikada bir çalıştıracak bir görev ekleyin. Örnek:

   ```cron
   * * * * * /usr/bin/php /var/www/hatirlatici/reminder.php
   ```

   Cron işleminin de `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `APP_URL` ve `APP_TIMEZONE` ortam değişkenlerini alması gerekir. Hosting paneliniz cron için ayrı ortam değişkenleri sunuyorsa aynı değerleri orada tanımlayın. Hatırlatmalar zamanı gelmiş ve son tarihi henüz geçmemiş görevler için gönderilir; geçici SMTP hatalarında en az 15 dakika sonra yeniden denenir.

## Yerel kullanım ve kayıtlar

`localhost`, `*.localhost`, `*.test`, `127.0.0.1` ve `::1` adreslerinde hatırlatma e-postaları gönderilmez. Cron da `APP_URL` değerini denetler. Geliştirme ortamında hatırlatıcı ayarlarını ve görev kayıtlarını deneyebilir, e-posta gönderimi yapmadan paneli kullanabilirsiniz.

Görevler MySQL'e eklenir; yönetim panelinde başlık, açıklama ve alıcı adresine göre aranıp sayfalanabilir. Uygulamada düzenleme veya silme işlemi yoktur; ayrıca veritabanı tetikleyicileri görev satırlarının `UPDATE` ve `DELETE` işlemlerini engeller. Hatırlatma gönderim durumu ayrı tabloda tutulur.

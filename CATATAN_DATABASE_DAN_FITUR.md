# Catatan Database dan Fitur Aplikasi

## Gambaran umum

Aplikasi Catatan Keuangan menggunakan MySQL. Nama databasenya adalah
`catatan_keuangan`, dengan pengaturan koneksi di `config/database.php` dan
struktur tabel di `database/schema.sql`.

## Struktur database

Relasi utamanya:

```text
users
 ├── transactions
 ├── stocks
 ├── user_categories
 ├── settings
 └── personal_budgets

transactions.loan_id
 └── mengaitkan pembayaran atau pengembalian dengan catatan pinjaman
```

| Tabel | Fungsi |
|---|---|
| `users` | Menyimpan akun pengguna, username, nama lengkap, dan password dalam bentuk hash. |
| `transactions` | Menyimpan pemasukan, pengeluaran, tabungan, hutang, piutang, cicilan, dan pengembalian. |
| `stocks` | Menyimpan barang usaha, jumlah stok, satuan, harga beli, dan harga jual. |
| `user_categories` | Menyimpan kategori keuangan tambahan yang dibuat pengguna. |
| `settings` | Menyediakan tempat untuk target bulanan dan mata uang tiap pengguna. |
| `personal_budgets` | Menyimpan anggaran pengeluaran pribadi per kategori dan per bulan. |

### Tabel transaksi

`transactions` adalah tabel utama pencatatan keuangan. `user_id` menandai
pemilik data, sementara `category_type` memisahkan ruang keuangan seperti
`business`, `personal`, dan `lender`. Kolom `type` membedakan transaksi menjadi
`income`, `expense`, `debt`, `receivable`, atau `savings`.

Kolom lainnya mencakup kategori transaksi, tanggal, keterangan, jumlah, nama
pihak terkait, tanggal jatuh tempo, serta status lunas. Nilai uang disimpan
dengan tipe `DECIMAL(15,2)`. Relasi `user_id` menggunakan foreign key; ketika
akun dihapus, data terkait ikut dihapus (`ON DELETE CASCADE`).

`loan_id` digunakan aplikasi untuk menghubungkan cicilan atau pengembalian ke
catatan pinjaman asal. Kolom ini memiliki indeks, tetapi tidak didefinisikan
sebagai foreign key pada skema saat ini.

## Fitur aplikasi

- **Kategori keuangan:** pengguna dapat memilih Bisnis, Catatan Pribadi, atau
  Pemberi Pinjaman. Data transaksi dipisahkan menurut pengguna dan kategori.
- **Dashboard:** menampilkan ringkasan keuangan dan transaksi terbaru untuk
  kategori yang dipilih, termasuk ringkasan bulanan.
- **Transaksi dan riwayat:** pengguna dapat mencatat serta mengedit transaksi,
  lalu menelusuri riwayat berdasarkan jenis dan bulan.
- **Anggaran pribadi:** menetapkan batas pengeluaran per kategori dan bulan,
  lalu membandingkan anggaran dengan pengeluaran aktual.
- **Tabungan:** mencatat tabungan di kategori Catatan Pribadi.
- **Hutang dan piutang:** mencatat kewajiban, piutang, status pembayaran, dan
  jatuh tempo. Hutang mendukung pencatatan cicilan.
- **Pinjaman dan pengembalian:** pada kategori Pemberi Pinjaman, pengguna dapat
  mencatat peminjam serta pembayaran kembali secara bertahap.
- **Stok usaha:** mengelola barang dan harga. Penambahan jumlah stok otomatis
  dicatat sebagai pengeluaran pembelian.
- **Laporan dan grafik:** menampilkan ringkasan berdasarkan periode, tren, dan
  kategori transaksi. Laporan dapat diunduh sebagai CSV atau dicetak/disimpan
  sebagai PDF melalui fitur cetak browser.
- **Pengaturan akun:** memperbarui nama dan password pengguna.

## Catatan perilaku

Pada alur transaksi dan stok yang diperiksa, penghapusan data tidak tersedia;
aksi hapus ditolak oleh aplikasi.

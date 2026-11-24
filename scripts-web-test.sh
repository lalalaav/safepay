#!/usr/bin/env bash
# Uji alur web tanpa browser: login, buat pesanan, catat hash asli, tolak hash palsu.
# Jalankan dari mana saja: bash C:/laragon/www/safepay/scripts-web-test.sh
set -uo pipefail
JAR="${TMPDIR:-/tmp}/safepay-cookies.txt"
BASE="http://127.0.0.1:8000"
APP="C:/laragon/www/safepay"
rm -f "$JAR"

tok() { curl -s --noproxy '*' -b "$JAR" -c "$JAR" "$1" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//;s/"$//'; }
count_tx() { (cd "$APP" && php artisan tinker --execute='echo App\Models\BlockchainTransaction::count();' 2>/dev/null | tr -dc '0-9'); }

echo "--- GET / (harus redirect ke /login) ---"
curl -s --noproxy '*' -o /dev/null -w "status=%{http_code} redirect=%{redirect_url}\n" "$BASE/"

echo "--- halaman login ---"
curl -s --noproxy '*' -b "$JAR" -c "$JAR" -o "${TMPDIR:-/tmp}/login.html" -w "status=%{http_code}\n" "$BASE/login"
grep -oE '<h1>[^<]*</h1>' "${TMPDIR:-/tmp}/login.html" | head -1

echo "--- login Andi ---"
CSRF=$(tok "$BASE/login")
curl -s --noproxy '*' -o /dev/null -w "status=%{http_code} redirect=%{redirect_url}\n" \
  -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
  -d "_token=$CSRF" -d "email=andi@safepay.test" -d "password=password"

echo "--- GET /dashboard ---"
curl -s --noproxy '*' -b "$JAR" -o "${TMPDIR:-/tmp}/dash.html" -w "status=%{http_code}\n" "$BASE/dashboard"
grep -oE 'Dashboard [A-Za-z]+' "${TMPDIR:-/tmp}/dash.html" | head -1
echo -n "kpi_ditemukan="; grep -c 'Saldo wallet kamu' "${TMPDIR:-/tmp}/dash.html"

echo "--- POST /orders ---"
CSRF=$(tok "$BASE/dashboard")
curl -s --noproxy '*' -o /dev/null -w "status=%{http_code}\n" -b "$JAR" -c "$JAR" -X POST "$BASE/orders" \
  -d "_token=$CSRF" -d "seller_id=2" -d "product_name=Sepatu" -d "amount=150000"

ORDER=$(cd "$APP" && php artisan tinker --execute='echo App\Models\Order::max("id");' 2>/dev/null | tr -dc '0-9')
echo "order_id=$ORDER"

echo "--- bayar on-chain dari CLI ---"
PAY=$(cd "$APP/blockchain" && AMOUNT=150000 npx hardhat run scripts/pay-one.ts --network localhost 2>/dev/null | grep -E '^\{')
echo "$PAY"
HASH=$(echo "$PAY" | sed -E 's/.*"tx_hash":"([^"]+)".*/\1/')
CID=$(echo "$PAY" | sed -E 's/.*"contract_id":([0-9]+).*/\1/')
DL=$(echo "$PAY" | sed -E 's/.*"deadline":"([0-9]+)".*/\1/')

echo "--- POST hash ASLI (harus diterima) ---"
CSRF=$(tok "$BASE/dashboard")
curl -s --noproxy '*' -o /dev/null -w "status=%{http_code}\n" -b "$JAR" -c "$JAR" -X POST "$BASE/orders/$ORDER/tx" \
  -d "_token=$CSRF" -d "tx_hash=$HASH" -d "event_type=PaymentCreated" -d "contract_id=$CID" -d "deadline=$DL"

curl -s --noproxy '*' -b "$JAR" -o "${TMPDIR:-/tmp}/dash2.html" "$BASE/dashboard"
echo -n "status_dashboard="; grep -oE 'FUNDED' "${TMPDIR:-/tmp}/dash2.html" | head -1
echo -n "baris_tx_tercatat="; count_tx

echo "--- POST hash PALSU (harus ditolak) ---"
CSRF=$(tok "$BASE/dashboard")
FAKE="0x$(printf 'ab%.0s' $(seq 1 32))"
curl -s --noproxy '*' -o /dev/null -w "status=%{http_code}\n" -b "$JAR" -c "$JAR" -X POST "$BASE/orders/$ORDER/tx" \
  -d "_token=$CSRF" -d "tx_hash=$FAKE" -d "event_type=Released" -d "contract_id=$CID"
curl -s --noproxy '*' -b "$JAR" -c "$JAR" "$BASE/dashboard" | grep -oE 'Verifikasi blockchain GAGAL[^<]*' | head -1
echo -n "baris_tx_setelah_hash_palsu="; count_tx

echo "--- explorer + verifikasi ---"
curl -s --noproxy '*' -b "$JAR" -o /dev/null -w "explorer=%{http_code}\n" "$BASE/explorer"
curl -s --noproxy '*' -b "$JAR" -o /dev/null -w "verify=%{http_code}\n" "$BASE/explorer/$HASH"
curl -s --noproxy '*' -b "$JAR" "$BASE/explorer/$HASH" | grep -oE 'SUKSES \(0x1\)' | head -1
echo

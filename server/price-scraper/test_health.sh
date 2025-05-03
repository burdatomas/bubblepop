#!/bin/bash

# Test script for price-scraper /health endpoint

API_KEY="14bfb4910dc3f34aa9fa478cf4d8d550"
RETRY_COUNT=3
RETRY_DELAY=5
INITIAL_DELAY=10

echo "Setting up environment..."
mkdir -p tests/tmp
chmod 775 tests/tmp
chown scraper-user:scraper-user tests/tmp
rm -f tests/tmp/health.json tests/tmp/health.err tests/tmp/health.json.status
echo "Setup complete"

curl_with_retry() {
  local url=$1
  local output=$2
  local error=$3
  local attempt=1
  while [ $attempt -le $RETRY_COUNT ]; do
    echo "Attempt $attempt of $RETRY_COUNT for $url..."
    curl --max-time 30 -s -o "$output" -w "%{http_code}" "$url" > "$output.status" 2> "$error"
    if [ -s "$output.status" ] && [ "$(cat "$output.status")" = "200" ] && grep -q '"status":"OK"' "$output"; then
      return 0
    fi
    echo "Failed to reach $url, HTTP status: $(cat "$output.status" 2>/dev/null || echo '000')"
    cat "$error"
    echo "Retrying in $RETRY_DELAY seconds..."
    sleep $RETRY_DELAY
    attempt=$((attempt + 1))
  done
  echo "Failed to reach $url after $RETRY_COUNT attempts"
  return 1
}

echo "Waiting $INITIAL_DELAY seconds for service startup..."
sleep $INITIAL_DELAY

echo "Testing /health endpoint..."
curl_with_retry "http://127.0.0.1:3000/health" "tests/tmp/health.json" "tests/tmp/health.err"
if [ $? -ne 0 ] || [ ! -f tests/tmp/health.json.status ] || [ "$(cat tests/tmp/health.json.status)" != "200" ] || ! grep -q '"status":"OK"' tests/tmp/health.json; then
  echo "Health endpoint test failed"
  echo "Health JSON:"
  cat tests/tmp/health.json 2>/dev/null || echo "No health.json file"
  echo "Health Error:"
  cat tests/tmp/health.err 2>/dev/null || echo "No health.err file"
  echo "Health Status:"
  cat tests/tmp/health.json.status 2>/dev/null || echo "No health.json.status file"
  echo "Directory listing:"
  ls -l tests/tmp
  ls -l logs/combined.log logs/error.log
  exit 1
fi
cat tests/tmp/health.json
echo "Health endpoint test complete"
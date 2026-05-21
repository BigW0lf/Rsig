#!/bin/bash
# Migration de la base mabase locale -> VPS
# Usage : bash migrate_db.sh <VPS_IP> <SSH_KEY_PATH>
# Ex    : bash migrate_db.sh 162.19.229.153 ~/.ssh/id_ed25519_vps_ovh

set -e

VPS_IP="${1:?Fournir IP du VPS}"
SSH_KEY="${2:?Fournir chemin clé SSH}"
DB_PASS="${DB_PASS:-postgres}"

echo "==> Dump de mabase en local..."
pg_dump -h localhost -U postgres -Fc mabase > /tmp/mabase.dump

echo "==> Transfert vers le VPS..."
scp -i "$SSH_KEY" /tmp/mabase.dump debian@${VPS_IP}:/tmp/mabase.dump

echo "==> Restauration sur le VPS..."
ssh -i "$SSH_KEY" debian@${VPS_IP} bash <<EOF
  # Attendre que le conteneur db soit prêt
  until docker exec rsig-db pg_isready -U postgres; do sleep 2; done

  # Restaurer
  docker exec -i rsig-db pg_restore \
    -U postgres -d mabase --no-owner --role=postgres \
    --clean --if-exists < /tmp/mabase.dump

  rm /tmp/mabase.dump
  echo "Restauration terminée."
EOF

rm /tmp/mabase.dump
echo "==> Migration terminée avec succès."

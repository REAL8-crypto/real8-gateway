# Deployment Setup Guide

This guide explains how to set up automatic deployment for REAL8 Gateway.

## GitHub Actions Secrets Required

You need to configure these secrets in your GitHub repository:

1. Go to **Settings** > **Secrets and variables** > **Actions**
2. Add the following secrets:

| Secret Name | Value |
|-------------|-------|
| `SSH_PRIVATE_KEY` | Contents of `~/.ssh/id_ereal8` private key |
| `REMOTE_HOST` | `45.136.71.131` |
| `REMOTE_USER` | `admin` |

### Getting the SSH Private Key

```bash
cat ~/.ssh/id_ereal8
```

Copy the entire output including `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`.

## How It Works

1. Every push to `main` branch triggers the workflow
2. GitHub Actions connects to the server via SSH
3. rsync syncs all files to `/var/www/html/wp-content/plugins/real8-gateway/`
4. Excludes: `.git`, `.github`, `node_modules`, `.DS_Store`, `*.log`
5. Verifies deployment by checking the version number

## Deployment Target

- **Server:** 45.136.71.131 (Webdock)
- **Path:** `/var/www/html/wp-content/plugins/real8-gateway/`
- **User:** admin (member of www-data group)

## Manual Deployment (if needed)

```bash
rsync -avz --delete \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'node_modules' \
  --exclude '.DS_Store' \
  -e "ssh -i ~/.ssh/id_ereal8" \
  /mnt/data/WebDes/REAL8/real8.org/www/wp-content/plugins/real8-gateway/ \
  admin@45.136.71.131:/var/www/html/wp-content/plugins/real8-gateway/
```

## Verifying Deployment

```bash
ssh -i ~/.ssh/id_ereal8 admin@45.136.71.131 'grep "Version:" /var/www/html/wp-content/plugins/real8-gateway/real8-gateway.php | head -1'
```

## Troubleshooting

### Permission Denied
- Ensure admin user is in www-data group
- Check file permissions: `ls -la /var/www/html/wp-content/plugins/`

### SSH Connection Failed
- Verify SSH key is correct in GitHub secrets
- Test manually: `ssh -i ~/.ssh/id_ereal8 admin@45.136.71.131`

### Workflow Not Running
- Check that you pushed to `main` branch
- View workflow runs at: https://github.com/REAL8-crypto/real8-gateway/actions

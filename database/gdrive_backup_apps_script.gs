/**
 * JMC Foodies — Google Drive backup receiver (Google Apps Script).
 *
 * The site uploads each hourly database backup here (upload_backup_to_drive()
 * in includes/functions.php); this saves it into the Drive folder below and
 * moves backups older than KEEP_DAYS to the Drive trash.
 *
 * ONE-TIME SETUP (signed in to the Google account that owns the folder):
 *   1. Go to https://script.google.com and click "New project".
 *   2. Delete the sample code, paste this whole file in, and set SECRET below
 *      to the same long random value as GDRIVE_BACKUP_SECRET in the server's
 *      config/gdrive.php.
 *   3. Click Deploy > New deployment > (gear icon) Web app, and set:
 *        Execute as:     Me
 *        Who has access: Anyone
 *      Click Deploy and allow the permissions it asks for (Drive access).
 *   4. Copy the Web app URL (ends in /exec) into GDRIVE_BACKUP_URL in the
 *      server's config/gdrive.php.
 *   5. On the admin Database Backup page, click "Run Backup Now" and check
 *      that a jmcfoodies-backup-*.sql.gz file appears in the folder.
 *
 * "Anyone" only means the URL can be called without a Google login; every
 * request without the right SECRET is refused, and the script can only add
 * files to (and clean up backups in) this one folder.
 *
 * After editing this script later, use Deploy > Manage deployments > edit >
 * Version: New version, so the same URL keeps working.
 */

const FOLDER_ID = '15KK1GmcLaEPyyurfR5tyaVJ6C8LJRGiY';
const SECRET = 'PASTE-THE-SAME-SECRET-AS-config/gdrive.php';
const KEEP_DAYS = 7;
const FILE_PREFIX = 'jmcfoodies-backup-';

function doPost(e) {
  try {
    const body = JSON.parse(e.postData.contents);
    if (!body.secret || body.secret !== SECRET) {
      return reply({ ok: false, error: 'Wrong secret — GDRIVE_BACKUP_SECRET must match SECRET in the Apps Script.' });
    }
    if (!body.filename || body.filename.indexOf(FILE_PREFIX) !== 0 || !body.data) {
      return reply({ ok: false, error: 'Missing or invalid backup file.' });
    }

    const folder = DriveApp.getFolderById(FOLDER_ID);
    const blob = Utilities.newBlob(Utilities.base64Decode(body.data), 'application/gzip', body.filename);
    const file = folder.createFile(blob);
    removeOldBackups(folder);
    return reply({ ok: true, id: file.getId() });
  } catch (err) {
    return reply({ ok: false, error: String(err) });
  }
}

// Only ever touches this script's own backup files, never anything else in
// the folder.
function removeOldBackups(folder) {
  const cutoff = Date.now() - KEEP_DAYS * 24 * 60 * 60 * 1000;
  const files = folder.getFiles();
  while (files.hasNext()) {
    const file = files.next();
    if (file.getName().indexOf(FILE_PREFIX) === 0 && file.getDateCreated().getTime() < cutoff) {
      file.setTrashed(true);
    }
  }
}

function reply(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

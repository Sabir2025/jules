function sendToTelegram(text, cargoId) {
  const url = `https://api.telegram.org/bot${CONFIG.BOT_TOKEN}/sendMessage`;

  // Inline keyboard with "Take Cargo" button
  // We'll use a link to the Web App or a specific bot link that opens the Mini App
  const keyboard = {
    inline_keyboard: [[
      {
        text: "Взять груз",
        url: `${CONFIG.WEBAPP_URL}?page=driver-app&cargoId=${cargoId}`
      }
    ]]
  };

  const payload = {
    chat_id: CONFIG.GROUP_CHAT_ID || "-1002361668875",
    text: text,
    parse_mode: 'Markdown',
    reply_markup: JSON.stringify(keyboard)
  };

  const options = {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  };

  const response = UrlFetchApp.fetch(url, options);
  return JSON.parse(response.getContentText());
}

/**
 * Webhook for Telegram Bot
 */
function doPost(e) {
  const contents = JSON.parse(e.postData.contents);
  // Log message to find Chat ID if needed
  // console.log(contents);

  if (contents.message && contents.message.chat) {
    // Helpful to find the chat ID when the bot is added to a group
  }

  return ContentService.createTextOutput(JSON.stringify({status: 'ok'})).setMimeType(ContentService.MimeType.JSON);
}

/**
 * Function to be called from Driver Mini App
 */
function confirmCargo(cargoId, driverName, driverPhone) {
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = ss.getSheetByName('Cargo');
  const data = sheet.getDataRange().getValues();

  for (let i = 1; i < data.length; i++) {
    if (data[i][0] == cargoId) {
      if (data[i][5] == 'Закрыто') {
        return { success: false, message: 'Этот рейс уже забронирован' };
      }

      const rowIdx = i + 1;
      sheet.getRange(rowIdx, 6).setValue('Закрыто');
      sheet.getRange(rowIdx, 8).setValue(`${driverName} (${driverPhone})`);
      sheet.getRange(rowIdx, 9).setValue(new Date().toISOString());

      // Notify driver in Telegram (optional, if we have his ID)
      // And maybe update the message in the group
      updateTelegramMessage(data[i][6], data[i], driverName);

      return { success: true };
    }
  }
  return { success: false, message: 'Груз не найден' };
}

function updateTelegramMessage(messageId, cargoData, driverName) {
  if (!messageId) return;

  const url = `https://api.telegram.org/bot${CONFIG.BOT_TOKEN}/editMessageText`;
  const text = `✅ *Груз ЗАБРОНИРОВАН*\n\n` +
               `📍 Маршрут: ${cargoData[1]}\n` +
               `🚛 Груз: ${cargoData[2]}\n` +
               `💰 Ставка: ${cargoData[4]} тенге\n\n` +
               `👤 Забрал: ${driverName}\n` +
               `#ID${cargoData[0]}`;

  const payload = {
    chat_id: CONFIG.GROUP_CHAT_ID || "-1002361668875",
    message_id: messageId,
    text: text,
    parse_mode: 'Markdown'
  };

  UrlFetchApp.fetch(url, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  });
}

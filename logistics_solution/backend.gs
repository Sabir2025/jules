/**
 * ГЛАВНЫЙ СКРИПТ УПРАВЛЕНИЯ ЛОГИСТИКОЙ
 * Устанавливается в Google Apps Script
 */

const TOKENS = {
  TELEGRAM: 'YOUR_TELEGRAM_BOT_TOKEN'
};

const SHEET_NAMES = {
  ORDERS: 'Заказы',
  DRIVERS: 'Водители'
};

/**
 * Обработка входящих сообщений от Telegram (Webhook)
 */
function doPost(e) {
  try {
    const contents = JSON.parse(e.postData.contents);

    if (contents.callback_query) {
      handleCallback(contents.callback_query);
    } else if (contents.message) {
      handleMessage(contents.message);
    }

    return ContentService.createTextOutput("ok").setMimeType(ContentService.MimeType.TEXT);
  } catch (error) {
    Logger.log("Error in doPost: " + error.toString());
  }
}

/**
 * Обработка текстовых сообщений
 */
function handleMessage(msg) {
  const chatId = msg.chat.id;
  const text = msg.text;

  if (text === '/start') {
    sendMessage(chatId, "Добро пожаловать в систему контроля логистики! Пожалуйста, ожидайте назначения заказов.");
    // Здесь можно добавить логику автоматической регистрации водителя
    registerDriver(chatId, msg.from.first_name);
  }
}

/**
 * Обработка нажатий на кнопки
 */
function handleCallback(query) {
  const chatId = query.message.chat.id;
  const data = query.data; // Пример: "status_loading_ID123"
  const messageId = query.message.message_id;

  const parts = data.split('_');
  const action = parts[0];
  const status = parts[1];
  const orderId = parts[2];

  if (action === 'status') {
    updateOrderStatus(orderId, status);

    // Редактируем сообщение, чтобы подтвердить выбор
    const statusText = getStatusLabel(status);
    editMessage(chatId, messageId, "Статус заказа " + orderId + " изменен на: " + statusText);

    // Предлагаем следующий шаг
    sendNextStep(chatId, orderId, status);
  }
}

/**
 * Обновление статуса в таблице
 */
function updateOrderStatus(orderId, status) {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET_NAMES.ORDERS);
  const data = sheet.getDataRange().getValues();

  for (let i = 1; i < data.length; i++) {
    if (data[i][0] == orderId) { // Столбец A - ID Заказа
      sheet.getRange(i + 1, 6).setValue(getStatusLabel(status)); // Столбец F - Статус
      sheet.getRange(i + 1, 7).setValue(new Date()); // Столбец G - Время обновления
      break;
    }
  }
}

/**
 * Отправка сообщения с кнопками
 */
function sendNextStep(chatId, orderId, currentStatus) {
  let buttons = [];
  let text = "Выберите следующий этап:";

  if (currentStatus === 'accepted') {
    buttons = [[{ text: "🚚 Прибыл на погрузку", callback_data: "status_loading_" + orderId }]];
  } else if (currentStatus === 'loading') {
    buttons = [[{ text: "📦 Погрузка завершена", callback_data: "status_loaded_" + orderId }]];
  } else if (currentStatus === 'loaded') {
    buttons = [[{ text: "🏁 Прибыл на выгрузку", callback_data: "status_unloading_" + orderId }]];
  } else if (currentStatus === 'unloading') {
    buttons = [[{ text: "✅ Заказ завершен", callback_data: "status_completed_" + orderId }]];
  }

  if (buttons.length > 0) {
    sendMessage(chatId, text, { inline_keyboard: buttons });
  }
}

/**
 * Регистрация водителя в таблице
 */
function registerDriver(chatId, name) {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET_NAMES.DRIVERS);
  const data = sheet.getDataRange().getValues();
  let found = false;

  for (let i = 1; i < data.length; i++) {
    if (data[i][2] == chatId) {
      found = true;
      break;
    }
  }

  if (!found) {
    sheet.appendRow([name, "", chatId]);
  }
}

/**
 * Хелперы для Telegram API
 */
function sendMessage(chatId, text, replyMarkup) {
  const url = "https://api.telegram.org/bot" + TOKENS.TELEGRAM + "/sendMessage";
  const payload = {
    chat_id: chatId,
    text: text,
    reply_markup: replyMarkup ? JSON.stringify(replyMarkup) : null
  };
  UrlFetchApp.fetch(url, {
    method: "post",
    payload: payload
  });
}

function editMessage(chatId, messageId, text) {
  const url = "https://api.telegram.org/bot" + TOKENS.TELEGRAM + "/editMessageText";
  const payload = {
    chat_id: chatId,
    message_id: messageId,
    text: text
  };
  UrlFetchApp.fetch(url, {
    method: "post",
    payload: payload
  });
}

function getStatusLabel(status) {
  const labels = {
    'accepted': 'Принят',
    'loading': 'На погрузке',
    'loaded': 'В пути',
    'unloading': 'На выгрузке',
    'completed': 'Завершен'
  };
  return labels[status] || status;
}

/**
 * Триггер: отправка заказа водителю при изменении в таблице
 * (Нужно настроить триггер "При редактировании" в Apps Script)
 */
function onEditTrigger(e) {
  const range = e.range;
  const sheet = range.getSheet();

  if (sheet.getName() === SHEET_NAMES.ORDERS && range.getColumn() === 3) { // Если изменили столбец "Водитель"
    const row = range.getRow();
    const orderId = sheet.getRange(row, 1).getValue();
    const route = sheet.getRange(row, 2).getValue();
    const driverChatId = sheet.getRange(row, 5).getValue(); // Telegram ID водителя

    if (driverChatId) {
      const message = "🔔 Вам назначен новый заказ!\n🆔 ID: " + orderId + "\n📍 Маршрут: " + route;
      const buttons = {
        inline_keyboard: [[{ text: "✅ Принять заказ", callback_data: "status_accepted_" + orderId }]]
      };
      sendMessage(driverChatId, message, buttons);
    }
  }
}

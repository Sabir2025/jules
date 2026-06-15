function registerUser(data) {
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = ss.getSheetByName('Users');
  const users = sheet.getDataRange().getValues();

  // Check if user exists
  for (let i = 1; i < users.length; i++) {
    if (users[i][0] == data.phone) {
      return { success: false, message: 'Пользователь с таким номером уже существует' };
    }
  }

  sheet.appendRow([data.phone, data.name, data.password]);
  return { success: true };
}

function loginUser(data) {
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = ss.getSheetByName('Users');
  const users = sheet.getDataRange().getValues();

  for (let i = 1; i < users.length; i++) {
    if (users[i][0] == data.phone && users[i][2] == data.password) {
      return { success: true, user: { phone: users[i][0], name: users[i][1] } };
    }
  }
  return { success: false, message: 'Неверный номер телефона или пароль' };
}

function addCargo(data) {
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = ss.getSheetByName('Cargo');
  const cargoId = 'ORD-' + Math.floor(Date.now() / 1000);
  const dateStr = new Date().toISOString();

  const row = [
    cargoId,
    data.route,
    data.cargo,
    data.weight,
    data.price,
    'Поиск',
    '', // Telegram ID
    '', // Кто забрал
    '', // Дата бронирования
    data.userPhone,
    dateStr
  ];

  sheet.appendRow(row);

  // Send to Telegram
  try {
    const msg = `📦 *Новый груз*\n\n` +
                `📍 Маршрут: ${data.route}\n` +
                `🚛 Груз: ${data.cargo}\n` +
                `⚖️ Вес/Объем: ${data.weight}\n` +
                `💰 Ставка: ${data.price} тенге\n\n` +
                `#ID${cargoId}`;

    // We'll need to send message and save message_id back
    // This part requires Telegram integration
    const tgResult = sendToTelegram(msg, cargoId);
    if (tgResult && tgResult.result) {
      const lastRow = sheet.getLastRow();
      sheet.getRange(lastRow, 7).setValue(tgResult.result.message_id);
    }
  } catch (e) {
    console.error('TG Error: ' + e);
  }

  return { success: true, id: cargoId };
}

function getStats(userPhone) {
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = ss.getSheetByName('Cargo');
  const data = sheet.getDataRange().getValues();

  let stats = {
    active: 0,
    totalRevenue: 0,
    history: [],
    byDriver: {}
  };

  for (let i = 1; i < data.length; i++) {
    const cargoUserPhone = data[i][9];
    const status = data[i][5];
    const price = parseFloat(data[i][4]) || 0;
    const date = data[i][10];
    const driver = data[i][7];

    if (cargoUserPhone == userPhone) {
      if (status == 'Поиск') stats.active++;
      if (status == 'Закрыто') {
        stats.totalRevenue += price;
        if (driver) {
          stats.byDriver[driver] = (stats.byDriver[driver] || 0) + 1;
        }
      }

      stats.history.push({
        date: date,
        status: status,
        price: price
      });
    }
  }

  return stats;
}

function getMyCargo(userPhone) {
  const ss = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = ss.getSheetByName('Cargo');
  const data = sheet.getDataRange().getValues();
  const myCargo = [];

  for (let i = data.length - 1; i >= 1; i--) {
    if (data[i][9] == userPhone) {
      myCargo.push({
        id: data[i][0],
        route: data[i][1],
        cargo: data[i][2],
        weight: data[i][3],
        price: data[i][4],
        status: data[i][5],
        date: data[i][10]
      });
    }
  }
  return myCargo;
}

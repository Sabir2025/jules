const CONFIG = {
  BOT_TOKEN: '8809578556:AAFSkAZltVfxB0BFoB-KCLxYzcap1fhtGEM',
  GROUP_CHAT_ID: '-1002361668875',
  SPREADSHEET_ID: '1BT1PrFmpwHqBS5iWfjjryg_VAlvXqLvohxAxu2u2eoo',
  WEBAPP_URL: '' // Вставьте сюда URL после деплоя
};

function doGet(e) {
  const page = e.parameter.page || 'index';
  const cargoId = e.parameter.cargoId || '';

  const template = HtmlService.createTemplateFromFile(page);
  template.cargoId = cargoId;

  return template.evaluate()
    .setTitle('MoveX Logistics')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function include(filename) {
  return HtmlService.createHtmlOutputFromFile(filename).getContent();
}

/**
 * Routing for client-side calls
 */
function apiHandler(action, data) {
  switch (action) {
    case 'register':
      return registerUser(data);
    case 'login':
      return loginUser(data);
    case 'addCargo':
      return addCargo(data);
    case 'getStats':
      return getStats(data);
    case 'getMyCargo':
      return getMyCargo(data);
    default:
      throw new Error('Unknown action');
  }
}

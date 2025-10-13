
const socket = new WebSocket('wss://ws.finnhub.io?token=d3mio7pr01qmso340tq0d3mio7pr01qmso340tqg');

// Connection opened -> Subscribe
socket.addEventListener('open', function (event) {
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'AAPL'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'MSFT'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'GOOGL'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'AMZN'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'TSLA'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'FB'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'NVDA'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'JPM'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'V'}))
    socket.send(JSON.stringify({'type':'subscribe', 'symbol': 'JNJ'}))
});

// Listen for messages
socket.addEventListener('message', function (event) {
    console.log('Message from server ', event.data);
});

// Unsubscribe
 var unsubscribe = function(symbol) {
    socket.send(JSON.stringify({'type':'unsubscribe','symbol': symbol}))
}

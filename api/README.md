# AWS SQS FIFO Queue API

A REST API for managing AWS SQS FIFO queues and audit log messages.

## Base URL
```
http://localhost/project1/api
```

## Endpoints

### 1. API Information
```
GET /api/info
```
Returns API information and available endpoints.

**Response:**
```json
{
  "success": true,
  "status": 200,
  "data": {
    "api": "AWS SQS FIFO Queue API",
    "version": "1.0.0",
    "endpoints": { ... }
  }
}
```

### 2. Send Audit Log Message
```
POST /api/audit-log
Content-Type: application/json
```

**Required Fields:**
- `ChangeID` - Unique identifier for the change
- `Type` - INSERT, UPDATE, or DELETE
- `TableName` - Name of the affected table
- `PrimaryKeyField` - Primary key field name
- `PrimaryKeyValue` - Primary key value
- `FieldName` - Name of the changed field
- `OldValue` - Previous value
- `NewValue` - New value
- `DateChanged` - When the change occurred
- `UserID` - ID of user making the change
- `IPAddress` - Client IP address
- `Url` - Request URL
- `ReferringUrl` - Referring URL

**Optional Fields:**
- `queue_name` - Custom queue name (default: audit-log-queue)
- `message_group_id` - Custom group ID (default: TableName-UserID)
- `queue_config` - Queue configuration object

**Example Request:**
```json
{
  "ChangeID": "change_12345",
  "Type": "UPDATE",
  "TableName": "users",
  "PrimaryKeyField": "id",
  "PrimaryKeyValue": "123",
  "FieldName": "email",
  "OldValue": "old@example.com",
  "NewValue": "new@example.com",
  "DateChanged": "2025-08-29 18:42:23",
  "UserID": "1",
  "IPAddress": "127.0.0.1",
  "Url": "/admin/users/edit",
  "ReferringUrl": "/admin/users",
  "queue_name": "audit-log-queue"
}
```

**Response (201):**
```json
{
  "success": true,
  "status": 201,
  "data": {
    "message": "Audit log message sent successfully",
    "data": {
      "queue_name": "audit-log-queue.fifo",
      "message_group_id": "users-1",
      "change_id": "change_12345",
      "table_name": "users",
      "processed_at": "2025-08-29 18:42:23"
    }
  }
}
```

### 3. Consume Messages
```
GET /api/messages?queue_name={name}&max_messages={count}&wait_time={seconds}
```

**Parameters:**
- `queue_name` (required) - Name of the queue to consume from
- `max_messages` (optional) - Maximum messages to retrieve (default: 10)
- `wait_time` (optional) - Long polling wait time in seconds (default: 5)

**Example:**
```
GET /api/messages?queue_name=audit-log-queue&max_messages=5&wait_time=10
```

**Response (200):**
```json
{
  "success": true,
  "status": 200,
  "data": {
    "queue_name": "audit-log-queue.fifo",
    "message_count": 2,
    "messages": [
      {
        "message_id": "12345-abcd-6789",
        "body": {
          "ChangeID": "change_12345",
          "Type": "UPDATE",
          "TableName": "users",
          ...
        },
        "group_id": "users-1",
        "received_at": "2025-08-29 18:42:23",
        "deleted": true
      }
    ]
  }
}
```

### 4. Create Queue
```
POST /api/queues
Content-Type: application/json
```

**Request:**
```json
{
  "queue_name": "my-custom-queue",
  "attributes": {
    "MessageRetentionPeriod": "1209600",
    "VisibilityTimeout": "30",
    "ReceiveMessageWaitTimeSeconds": "20"
  }
}
```

**Response (201):**
```json
{
  "success": true,
  "status": 201,
  "data": {
    "message": "Queue created successfully",
    "queue_name": "my-custom-queue.fifo",
    "queue_url": "https://sqs.region.amazonaws.com/account/queue-name"
  }
}
```

### 5. List Queues
```
GET /api/queues
```

**Response (200):**
```json
{
  "success": true,
  "status": 200,
  "data": {
    "message": "Queue listing not implemented yet",
    "note": "Use specific queue names to check if they exist"
  }
}
```

## Error Responses

All errors follow this format:
```json
{
  "success": false,
  "status": 400,
  "error": "Error message",
  "details": "Additional details if available",
  "timestamp": "2025-08-29 18:42:23"
}
```

**Common Status Codes:**
- `400` - Bad Request (missing fields, invalid JSON)
- `404` - Not Found (queue doesn't exist, endpoint not found)
- `405` - Method Not Allowed
- `500` - Internal Server Error

## Integration Examples

### PHP (cURL)
```php
$data = [
    'ChangeID' => 'change_' . uniqid(),
    'Type' => 'UPDATE',
    'TableName' => 'users',
    // ... other required fields
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/project1/api/audit-log');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$result = json_decode($response, true);
curl_close($ch);
```

### JavaScript (Fetch)
```javascript
const data = {
    ChangeID: 'change_' + Date.now(),
    Type: 'UPDATE',
    TableName: 'users',
    // ... other required fields
};

fetch('http://localhost/project1/api/audit-log', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
})
.then(response => response.json())
.then(result => console.log(result));
```

### Python (requests)
```python
import requests
import json

data = {
    'ChangeID': 'change_12345',
    'Type': 'UPDATE',
    'TableName': 'users',
    # ... other required fields
}

response = requests.post(
    'http://localhost/project1/api/audit-log',
    headers={'Content-Type': 'application/json'},
    data=json.dumps(data)
)

result = response.json()
print(result)
```

## Notes

- All queue names automatically get `.fifo` suffix
- Messages are automatically deleted after consumption
- Queue configuration only applies when creating new queues
- CORS is enabled for cross-origin requests
- All timestamps are in `Y-m-d H:i:s` format

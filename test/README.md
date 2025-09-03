# 🧪 Testing Setup for Console Error Logger

This directory contains a complete testing environment for the Console Error Logger WordPress plugin that works independently of WordPress.

## Quick Start

1. **Start the test server:**
   ```bash
   cd /path/to/project
   php -S localhost:8080 test/test-server.php
   ```

2. **Open test interface:**
   ```bash
   open http://localhost:8080
   ```

3. **Run automated tests:**
   ```bash
   node test/automated-tests.js
   ```

## Testing Components

### 1. Mock WordPress Environment (`mock-wordpress.php`)
- Simulates WordPress functions and database operations
- Uses SQLite for easy testing (no MySQL setup required)
- Creates `wp_console_errors` table automatically
- Provides all necessary WordPress mocks (nonce, sanitization, etc.)

### 2. Test Server (`test-server.php`)
- Standalone PHP server that handles AJAX requests
- Routes:
  - `/` - Test interface
  - `/ajax` - AJAX endpoint (simulates wp-admin/admin-ajax.php)
  - `/api/errors` - Get logged errors
  - `/api/stats` - Get error statistics
  - `/api/clear` - Clear all errors
  - `/assets/*` - Serve plugin assets

### 3. Interactive Test Interface (`test-interface.html`)
- Beautiful web interface for manual testing
- Test buttons for all error types:
  - JavaScript errors (Error, TypeError, ReferenceError)
  - Console methods (error, warn, log, info)
  - Network errors (AJAX, Fetch, Resource loading)
  - Async errors (Promise rejections, timeouts)
  - Login timeout simulation
- Live error log display
- Real-time statistics
- Custom code execution
- Export functionality

### 4. Automated Test Suite (`automated-tests.js`)
- Comprehensive Node.js test suite
- Tests all major functionality:
  - Basic error logging
  - All error types
  - Rate limiting
  - Data validation
  - Database retrieval
  - Statistics generation
- Provides detailed pass/fail reporting

## Usage Examples

### Manual Testing Workflow
1. Start server: `php -S localhost:8080 test/test-server.php`
2. Open browser to `http://localhost:8080`
3. Click test buttons to generate different error types
4. Watch live error log and statistics update
5. Use "Clear All Errors" to reset between tests

### Automated Testing Workflow
```bash
# Terminal 1: Start test server
php -S localhost:8080 test/test-server.php

# Terminal 2: Run automated tests
node test/automated-tests.js
```

### Debugging Database Issues
```bash
# Check the SQLite database directly
sqlite3 test/test_database.db "SELECT * FROM wp_console_errors;"

# View table structure
sqlite3 test/test_database.db ".schema wp_console_errors"

# Clear database manually
sqlite3 test/test_database.db "DELETE FROM wp_console_errors;"
```

### Testing Error Types

Each error type can be tested both manually and automatically:

| Error Type | Manual Test | Automated Test |
|------------|-------------|----------------|
| JavaScript Error | "Throw Error" button | `testBasicErrorLogging()` |
| Console Error | "Console Error" button | `testErrorTypes()` |
| AJAX Error | "AJAX Error" button | Network error simulation |
| Promise Rejection | "Promise Rejection" button | Async error testing |
| Login Timeout | "Login Timeout" button | Timeout simulation |

### Custom Testing
Use the custom code execution box to test specific scenarios:

```javascript
// Test custom error with stack trace
function deepFunction() {
    throw new Error('Deep error for testing stack trace');
}
deepFunction();

// Test async error handling
async function testAsync() {
    await Promise.reject('Async rejection test');
}
testAsync();

// Test console method override
console.error('Custom console error test', {data: 'additional info'});
```

## File Structure

```
test/
├── README.md              # This documentation
├── mock-wordpress.php     # WordPress environment simulation
├── test-server.php        # Standalone test server
├── test-interface.html    # Interactive web interface
├── automated-tests.js     # Node.js test suite
└── test_database.db       # SQLite database (auto-created)
```

## Troubleshooting

### Common Issues

1. **Port already in use:**
   ```bash
   # Use different port
   php -S localhost:8081 test/test-server.php
   ```

2. **Database permissions:**
   ```bash
   # Ensure test directory is writable
   chmod 755 test/
   ```

3. **Missing dependencies:**
   ```bash
   # Install Node.js for automated tests
   # Install PHP with SQLite support
   ```

### Debug Mode
The test server runs with full error reporting enabled. Check the terminal where you started `php -S` for detailed error messages.

### Performance Testing
To test under load:
```bash
# Use ab (Apache Bench) or similar
ab -n 100 -c 10 -p test/sample-error.json -T 'application/x-www-form-urlencoded' http://localhost:8080/ajax
```

## Integration with WordPress

When ready to test in actual WordPress:

1. Copy plugin to WordPress plugins directory
2. Activate plugin
3. Use existing `test-errors.html` in WordPress context
4. Check admin interface under Tools > Console Errors

The testing environment closely mimics WordPress behavior, so code that works here should work in WordPress.
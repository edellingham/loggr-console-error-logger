/**
 * Automated Test Suite for Console Error Logger
 * Run with: node automated-tests.js
 */

const http = require('http');
const querystring = require('querystring');

class TestSuite {
    constructor() {
        this.baseUrl = 'http://localhost:8080';
        this.testResults = [];
        this.sessionId = 'test_session_' + Date.now();
    }
    
    async runAllTests() {
        console.log('🚀 Starting Console Error Logger Test Suite\n');
        
        await this.clearDatabase();
        await this.sleep(500);
        
        const tests = [
            () => this.testBasicErrorLogging(),
            () => this.testErrorTypes(),
            () => this.testRateLimiting(),
            () => this.testDataValidation(),
            () => this.testDatabaseRetrieval(),
            () => this.testStatistics()
        ];
        
        for (let i = 0; i < tests.length; i++) {
            console.log(`Running test ${i + 1}/${tests.length}...`);
            try {
                await tests[i]();
                console.log(`✅ Test ${i + 1} passed\n`);
            } catch (error) {
                console.log(`❌ Test ${i + 1} failed: ${error.message}\n`);
                this.testResults.push({ test: i + 1, status: 'failed', error: error.message });
            }
        }
        
        this.printResults();
    }
    
    async testBasicErrorLogging() {
        const errorData = {
            error_type: 'javascript_error',
            error_message: 'Test error message',
            error_source: 'test.js',
            error_line: 42,
            error_column: 10,
            stack_trace: 'Error at test.js:42:10',
            session_id: this.sessionId,
            page_url: 'http://localhost/test',
            user_agent: 'Test User Agent'
        };
        
        const response = await this.logError(errorData);
        if (!response.success) {
            throw new Error('Failed to log basic error: ' + JSON.stringify(response));
        }
        
        this.testResults.push({ test: 'Basic Error Logging', status: 'passed' });
    }
    
    async testErrorTypes() {
        const errorTypes = [
            'javascript_error',
            'console_error',
            'console_warning',
            'ajax_error',
            'fetch_error',
            'resource_error',
            'unhandled_rejection',
            'login_timeout'
        ];
        
        for (const type of errorTypes) {
            const errorData = {
                error_type: type,
                error_message: `Test ${type} message`,
                session_id: this.sessionId
            };
            
            const response = await this.logError(errorData);
            if (!response.success) {
                throw new Error(`Failed to log ${type}: ` + JSON.stringify(response));
            }
        }
        
        // Verify all types were logged
        const stats = await this.getStats();
        if (stats.by_type.length < errorTypes.length) {
            throw new Error(`Expected ${errorTypes.length} error types, got ${stats.by_type.length}`);
        }
        
        this.testResults.push({ test: 'Error Types', status: 'passed' });
    }
    
    async testRateLimiting() {
        // Send many errors rapidly to test rate limiting
        const promises = [];
        for (let i = 0; i < 15; i++) {
            const errorData = {
                error_type: 'javascript_error',
                error_message: `Rate limit test error ${i}`,
                session_id: this.sessionId + '_rate_test'
            };
            promises.push(this.logError(errorData));
        }
        
        const responses = await Promise.all(promises);
        const successCount = responses.filter(r => r.success).length;
        
        // Should have some rate limiting after 10 errors
        if (successCount >= 15) {
            throw new Error('Rate limiting not working - all 15 errors were logged');
        }
        
        this.testResults.push({ test: 'Rate Limiting', status: 'passed' });
    }
    
    async testDataValidation() {
        // Test missing required fields
        const invalidData = {
            // Missing error_type and error_message
            session_id: this.sessionId
        };
        
        const response = await this.logError(invalidData);
        if (response.success) {
            throw new Error('Should have rejected invalid data');
        }
        
        this.testResults.push({ test: 'Data Validation', status: 'passed' });
    }
    
    async testDatabaseRetrieval() {
        // Log a unique error
        const uniqueMessage = 'Unique test error ' + Date.now();
        await this.logError({
            error_type: 'javascript_error',
            error_message: uniqueMessage,
            session_id: this.sessionId
        });
        
        // Retrieve and verify
        const errors = await this.getErrors();
        const found = errors.find(e => e.error_message === uniqueMessage);
        
        if (!found) {
            throw new Error('Could not retrieve logged error from database');
        }
        
        this.testResults.push({ test: 'Database Retrieval', status: 'passed' });
    }
    
    async testStatistics() {
        const stats = await this.getStats();
        
        if (!stats.total || stats.total < 1) {
            throw new Error('Statistics should show logged errors');
        }
        
        if (!stats.by_type || !Array.isArray(stats.by_type)) {
            throw new Error('Statistics should include error type breakdown');
        }
        
        this.testResults.push({ test: 'Statistics', status: 'passed' });
    }
    
    // Helper methods
    async logError(errorData) {
        const postData = querystring.stringify({
            action: 'cel_log_error',
            nonce: 'test_nonce',
            error_data: JSON.stringify(errorData)
        });
        
        return new Promise((resolve, reject) => {
            const req = http.request({
                hostname: 'localhost',
                port: 8080,
                path: '/ajax',
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Content-Length': Buffer.byteLength(postData)
                }
            }, (res) => {
                let data = '';
                res.on('data', (chunk) => data += chunk);
                res.on('end', () => {
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        reject(new Error('Invalid JSON response: ' + data));
                    }
                });
            });
            
            req.on('error', reject);
            req.write(postData);
            req.end();
        });
    }
    
    async getStats() {
        return new Promise((resolve, reject) => {
            http.get(`${this.baseUrl}/api/stats`, (res) => {
                let data = '';
                res.on('data', (chunk) => data += chunk);
                res.on('end', () => {
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        reject(new Error('Invalid JSON response: ' + data));
                    }
                });
            }).on('error', reject);
        });
    }
    
    async getErrors() {
        return new Promise((resolve, reject) => {
            http.get(`${this.baseUrl}/api/errors`, (res) => {
                let data = '';
                res.on('data', (chunk) => data += chunk);
                res.on('end', () => {
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        reject(new Error('Invalid JSON response: ' + data));
                    }
                });
            }).on('error', reject);
        });
    }
    
    async clearDatabase() {
        return new Promise((resolve, reject) => {
            const req = http.request({
                hostname: 'localhost',
                port: 8080,
                path: '/api/clear',
                method: 'POST'
            }, (res) => {
                let data = '';
                res.on('data', (chunk) => data += chunk);
                res.on('end', () => resolve(JSON.parse(data)));
            });
            
            req.on('error', reject);
            req.end();
        });
    }
    
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    printResults() {
        console.log('📊 Test Results Summary');
        console.log('========================');
        
        const passed = this.testResults.filter(r => r.status === 'passed').length;
        const failed = this.testResults.filter(r => r.status === 'failed').length;
        
        console.log(`✅ Passed: ${passed}`);
        console.log(`❌ Failed: ${failed}`);
        console.log(`📈 Success Rate: ${((passed / (passed + failed)) * 100).toFixed(1)}%\n`);
        
        if (failed > 0) {
            console.log('Failed Tests:');
            this.testResults.filter(r => r.status === 'failed').forEach(result => {
                console.log(`  - ${result.test}: ${result.error}`);
            });
        }
        
        process.exit(failed > 0 ? 1 : 0);
    }
}

// Run tests if this file is executed directly
if (require.main === module) {
    const testSuite = new TestSuite();
    
    console.log('🔧 Make sure the test server is running on localhost:8080');
    console.log('   Command: php -S localhost:8080 test/test-server.php\n');
    
    // Wait a moment then run tests
    setTimeout(() => {
        testSuite.runAllTests().catch(error => {
            console.error('Test suite failed:', error);
            process.exit(1);
        });
    }, 1000);
}
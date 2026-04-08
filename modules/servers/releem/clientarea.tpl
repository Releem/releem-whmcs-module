<div style="text-align: left;">
{if $releemPublicApiKey}
    <div class="alert alert-info">
        <p style="margin: 0 0 12px 0;"><strong>Install Releem Agent for cPanel/WHM</strong></p>
        <p style="margin: 0;">Use the public API key below when running the Releem WHM installer on your server.</p>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Public API Key</strong></div>
        <div class="panel-body">
            <code>{$releemPublicApiKey|escape}</code>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading" style="margin-top: 16px;"><strong>WHM Install Command</strong></div>
        <div class="panel-body">
            <p style="margin: 0 0 12px 0;">Run this command as <code>root</code> on your cPanel/WHM server.</p>
            <pre style="background: #f5f5f5; border: 1px solid #ddd; padding: 12px; min-height: 4.5em; white-space: pre-wrap;"><code>{$releemWhmInstallCommand|escape}</code></pre>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Installation Steps</strong></div>
        <div class="panel-body">
            <p style="margin: 0 0 12px 0;">Follow these steps on the target server:</p>
            <ol style="margin-bottom: 12px;">
                <li>Log in to the server over SSH as <code>root</code>.</li>
                <li>Run the WHM installer command shown above.</li>
                <li>Open <code>WHM &gt; Plugins &gt; Releem Database Advisor</code> after installation.</li>
            </ol>
            <p style="margin: 0;">The installer is idempotent, installs the Releem Agent, registers the WHM plugin, and disables conflicting cPanel MySQL auto-adjust settings.</p>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading" style="margin-top: 16px;"><strong>Documentation</strong></div>
        <div class="panel-body">
            <p style="margin: 0 0 12px 0;">Use these references for setup and troubleshooting:</p>
            <ul>
                <li><a href="{$releemDocsCpanelTroubleshootingUrl|escape}" target="_blank" rel="noopener">cPanel/WHM checks and troubleshooting</a></li>
                <li><a href="{$releemDocsMysqlPermissionsUrl|escape}" target="_blank" rel="noopener">MySQL permissions reference</a></li>
                <li><a href="{$releemDocsLinuxManualUrl|escape}" target="_blank" rel="noopener">Manual Linux installation</a></li>
            </ul>
        </div>
    </div>
{else}
    <div class="alert alert-warning">
        Releem WHM installation details are not available yet. Please wait until provisioning completes or contact support.
    </div>
{/if}
</div>

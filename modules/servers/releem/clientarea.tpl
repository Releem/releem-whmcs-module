{if $releemPublicApiKey}
<div class="alert alert-info">
    <strong>Install Releem Agent for cPanel/WHM</strong><br>
    Use the public API key below when running the Releem WHM installer on your server.
</div>

<div class="panel panel-default">
    <div class="panel-heading">Public API Key</div>
    <div class="panel-body">
        <code>{$releemPublicApiKey|escape}</code>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">WHM Install Command</div>
    <div class="panel-body">
        <p>Run this command as <code>root</code> on your cPanel/WHM server.</p>
        <pre style="white-space: pre-wrap;">{$releemWhmInstallCommand|escape}</pre>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Installation Steps</div>
    <div class="panel-body">
        <ol>
            <li>Log in to the server over SSH as <code>root</code>.</li>
            <li>Run the WHM installer command shown above.</li>
            <li>Open <code>WHM &gt; Plugins &gt; Releem Database Advisor</code> after installation.</li>
        </ol>
        <p>The installer is idempotent, installs the Releem Agent, registers the WHM plugin, and disables conflicting cPanel MySQL auto-adjust settings.</p>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">Documentation</div>
    <div class="panel-body">
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

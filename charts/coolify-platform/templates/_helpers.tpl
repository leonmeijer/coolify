{{/*
Expand the name of the chart.
*/}}
{{- define "coolify-platform.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "coolify-platform.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "coolify-platform.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "coolify-platform.labels" -}}
helm.sh/chart: {{ include "coolify-platform.chart" . }}
{{ include "coolify-platform.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "coolify-platform.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify-platform.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Docker host labels
*/}}
{{- define "coolify-platform.dockerHost.labels" -}}
{{ include "coolify-platform.labels" . }}
app.kubernetes.io/component: docker-host
coolify.io/managed: "true"
coolify.io/type: "docker-host"
{{- end }}

{{/*
Docker host selector labels
*/}}
{{- define "coolify-platform.dockerHost.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify-platform.name" . }}-docker-host
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: docker-host
{{- end }}

{{/*
Create the name of the service account to use
*/}}
{{- define "coolify-platform.serviceAccountName" -}}
{{- if .Values.rbac.create }}
{{- default (include "coolify-platform.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{/*
Detect if running on OpenShift
*/}}
{{- define "coolify-platform.isOpenShift" -}}
{{- if .Capabilities.APIVersions.Has "route.openshift.io/v1" -}}
true
{{- else -}}
false
{{- end -}}
{{- end }}

{{/*
Determine the platform type
*/}}
{{- define "coolify-platform.platformType" -}}
{{- if eq .Values.platform.type "auto" -}}
  {{- if eq (include "coolify-platform.isOpenShift" .) "true" -}}
openshift
  {{- else -}}
kubernetes
  {{- end -}}
{{- else -}}
{{- .Values.platform.type }}
{{- end -}}
{{- end }}

{{/*
Check if KubeVirt should be installed
*/}}
{{- define "coolify-platform.installKubeVirt" -}}
{{- if and .Values.kubevirt.install (ne (include "coolify-platform.isOpenShift" .) "true") -}}
true
{{- else -}}
false
{{- end -}}
{{- end }}

{{/*
Get the Docker host VM namespace
*/}}
{{- define "coolify-platform.vmNamespace" -}}
{{- default (printf "%s-vms" .Release.Name) .Values.dockerHosts.namespace }}
{{- end }}

{{/*
Get the SSH secret name
*/}}
{{- define "coolify-platform.sshSecretName" -}}
{{- if .Values.dockerHosts.ssh.existingSecret -}}
{{- .Values.dockerHosts.ssh.existingSecret }}
{{- else -}}
{{- printf "%s-ssh-keys" (include "coolify-platform.fullname" .) }}
{{- end -}}
{{- end }}

{{/*
Get the cloud-init secret name
*/}}
{{- define "coolify-platform.cloudInitSecretName" -}}
{{- printf "%s-cloudinit" (include "coolify-platform.fullname" .) }}
{{- end }}

{{/*
Get the Coolify internal service URL
*/}}
{{- define "coolify-platform.coolifyInternalUrl" -}}
{{- if .Values.coolify.enabled -}}
http://{{ .Release.Name }}-coolify.{{ .Release.Namespace }}.svc.cluster.local
{{- else -}}
{{- .Values.coolify.coolify.config.appUrl }}
{{- end -}}
{{- end }}

{{/*
Get storage class
*/}}
{{- define "coolify-platform.storageClass" -}}
{{- if .Values.dockerHosts.storage.storageClass -}}
{{- .Values.dockerHosts.storage.storageClass }}
{{- else if .Values.global.storageClass -}}
{{- .Values.global.storageClass }}
{{- end -}}
{{- end }}

{{/*
Pre-install hook annotations
*/}}
{{- define "coolify-platform.preInstallAnnotations" -}}
"helm.sh/hook": pre-install
"helm.sh/hook-weight": {{ .weight | quote }}
"helm.sh/hook-delete-policy": before-hook-creation,hook-succeeded
{{- end }}

{{/*
Post-install hook annotations
*/}}
{{- define "coolify-platform.postInstallAnnotations" -}}
"helm.sh/hook": post-install,post-upgrade
"helm.sh/hook-weight": {{ .weight | quote }}
"helm.sh/hook-delete-policy": before-hook-creation,hook-succeeded
{{- end }}

{{/*
Generate KubeVirt feature gates string
*/}}
{{- define "coolify-platform.kubevirtFeatureGates" -}}
{{- join "," .Values.kubevirt.featureGates }}
{{- end }}

{{/*
Check if network policy should be created
*/}}
{{- define "coolify-platform.createNetworkPolicy" -}}
{{- if and .Values.networkPolicy.enabled .Values.dockerHosts.enabled -}}
true
{{- else -}}
false
{{- end -}}
{{- end }}

{{/*
Image pull secrets for all components
*/}}
{{- define "coolify-platform.imagePullSecrets" -}}
{{- with .Values.global.imagePullSecrets }}
imagePullSecrets:
{{- range . }}
  - name: {{ . }}
{{- end }}
{{- end }}
{{- end }}
